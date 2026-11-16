<?php
namespace VOLUK;
if (!defined('ABSPATH')) { exit; }


// Limits are bytes; WordPress keeps UTF-8 characters intact when trimming.
function bounded_text($value,int $limit): string {
    if(!is_string($value))throw new \RuntimeException('Vigane tekstivälja tüüp.');
    return \wp_check_invalid_utf8(substr(plain_text($value),0,$limit),true);
}
function allowed_image($value,string $base): ?string {
    if(!is_string($value)||strlen($value)>2048)return null;
    $url=absolute_url($value,$base);if(!$url)return null;
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    $origin=strtolower((string)parse_url($base,PHP_URL_HOST));
    $origin=preg_replace('/^www\./','',$origin);
    if(!in_array($host,[$origin,'www.'.$origin],true))return null;
    $port=parse_url($url,PHP_URL_PORT);if($port!==null&&$port!==443)return null;
    return $url;
}
function validate_item(array $item,array $source): array {
    foreach(['title','url','date','excerpt','imageAlt'] as $key)if(!isset($item[$key])||!is_string($item[$key]))throw new \RuntimeException('Uudise andmeväljad ei ole korrektsed.');
    if(strlen($item['url'])>2048||strlen($item['date'])>64||!strtotime($item['date']))throw new \RuntimeException('Uudise URL või kuupäev ei ole korrektne.');
    $url=absolute_url($item['url'],$source['url']);
    if(!$url||preg_replace('/^www\./','',(string)parse_url($url,PHP_URL_HOST))!==preg_replace('/^www\./','',(string)parse_url($source['url'],PHP_URL_HOST)))throw new \RuntimeException('Uudise link ei kuulu algallikale.');
    $item['url']=$url;$item['title']=bounded_text($item['title'],500);
    if($item['title']==='')throw new \RuntimeException('Uudise pealkiri puudub.');
    $item['excerpt']=bounded_text($item['excerpt'],2000);$item['imageAlt']=bounded_text($item['imageAlt'],500);
    $item['image']=allowed_image($item['image']??null,$source['url']);
    $item['category']=news_category($item['title']);return $item;
}
function checked_wp_row($row): array {
    if(!is_array($row)||!is_string($row['link']??null)||strlen($row['link'])>2048||!is_array($row['title']??null)||!is_string($row['title']['rendered']??null)||strlen($row['title']['rendered'])>10000||!is_string($row['date_gmt']??null)||strlen($row['date_gmt'])>64)throw new \RuntimeException('WordPressi uudise struktuur ei ole korrektne.');
    if(isset($row['status'])&&!is_string($row['status']))throw new \RuntimeException('Vigane avaldamisolek.');
    foreach(['excerpt','content'] as $key)if(isset($row[$key])&&(!is_array($row[$key])||!is_string($row[$key]['rendered']??null)||strlen($row[$key]['rendered'])>1048576))throw new \RuntimeException('Vigane või liiga pikk uudise sisu.');
    if(isset($row['_embedded'])){
        $e=$row['_embedded'];if(!is_array($e))throw new \RuntimeException('Vigane meedia struktuur.');
        if(isset($e['wp:featuredmedia'])){
            if(!is_array($e['wp:featuredmedia']))throw new \RuntimeException('Vigane meedia loend.');
            foreach($e['wp:featuredmedia'] as $media){if(!is_array($media))throw new \RuntimeException('Vigane meediakirje.');foreach(['source_url','alt_text'] as $key)if(isset($media[$key])&&(!is_string($media[$key])||strlen($media[$key])>10000))throw new \RuntimeException('Vigane meediaväli.');}
        }
    }
    return $row;
}

function plain_text(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function html_document(string $html): \DOMXPath
{
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($doc);
}

function first_text(\DOMXPath $xpath, string $expression): string
{
    $nodes = $xpath->query($expression);
    return $nodes && $nodes->length ? plain_text($nodes->item(0)->textContent) : '';
}

function absolute_url(string $value, string $base): ?string
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '' || str_starts_with($value, '#')) return null;
    $b = parse_url($base);
    if (!$b || !isset($b['host'])) return null;
    if (str_starts_with($value, '//')) $value = 'https:' . $value;
    elseif (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
        $prefix = 'https://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        $value = $prefix . (str_starts_with($value, '/') ? $value : rtrim(str_replace('\\', '/', dirname($b['path'] ?? '/')), '/') . '/' . $value);
    }
    $parts = parse_url($value);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $segments = [];
    foreach (explode('/', $parts['path'] ?? '/') as $segment) {
        if ($segment === '..') array_pop($segments);
        elseif ($segment !== '.' && $segment !== '') $segments[] = $segment;
    }
    return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' . implode('/', $segments) . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function news_links(string $html, array $source, int $limit): array
{
    $xpath = html_document($html);
    $nodes = $xpath->query('//a[@href]');
    $found = [];
    $host = preg_replace('/^www\./i', '', (string)parse_url($source['url'], PHP_URL_HOST));
    foreach ($nodes as $node) {
        $url = absolute_url($node->getAttribute('href'), $source['url']);
        if (!$url) continue;
        $otherHost = preg_replace('/^www\./i', '', (string)parse_url($url, PHP_URL_HOST));
        if ($otherHost !== $host) continue;
        $url = preg_replace('~^https://www\.~i', 'https://', $url);
        $title = plain_text($node->textContent);
        $isNews = ($source['selector'] ?? 'news-path') === 'heading-link'
            ? (bool)preg_match('/\bh2\b/', $node->getAttribute('class'))
            : (bool)preg_match('~/uudised/.+~', (string)parse_url($url, PHP_URL_PATH));
        if (!$isNews || strlen($title) < 16 || preg_match('/^(loe edasi|image|vaata kõiki)/iu', $title)) continue;
        if (!isset($found[$url])) $found[$url] = ['title'=>$title, 'url'=>$url, 'order'=>count($found)];
        if (count($found) >= $limit) break;
    }
    return array_values($found);
}

function news_category(string $title): string
{
    $rules = [
        'Transport ja taristu'=>'liiklus|tänav|teekat|teetöö|bussi|raudtee|eratee|lum|veevär|elektrikatk|pumpla',
        'Haridus ja noored'=>'kool|laste|lastel|noor|õpil|haridus',
        'Juhtimine ja planeerimine'=>'istung|valitsus|volikogu|planeering|projekteerimis|eelarve|kinnistu.*\bDP\b',
        'Keskkond'=>'jäät|pakendi|loodus|keskkon',
        'Kultuur ja kogukond'=>'festival|kontsert|kultuur|muusika|sport|orkestr|linnakodanik',
        'Sotsiaal ja turvalisus'=>'sotsiaal|pääst|tuletõr|kriis|tervis|hoole',
        'Ettevõtlus ja vara'=>'rendile|enampakk|ettevõt|hange',
    ];
    foreach ($rules as $category=>$pattern) if (preg_match('~'.$pattern.'~iu', $title)) return $category;
    return 'Muud teated';
}

function news_details(string $html, array $item): array
{
    $xpath = html_document($html);
    $title = first_text($xpath, '//h1') ?: $item['title'];
    $meta = [];
    foreach ($xpath->query('//meta[@content]') as $tag) $meta[$tag->getAttribute('property') ?: $tag->getAttribute('name')] = $tag->getAttribute('content');
    $date = null;
    $hasTime = false;
    $zone = new \DateTimeZone('Europe/Tallinn');
    foreach (['article:published_time','datePublished','publish_date'] as $key) {
        if (empty($meta[$key])) continue;
        try { $date = new \DateTimeImmutable($meta[$key], $zone); $hasTime = true; break; } catch (\Exception) {}
    }
    if (!$date) {
        $value = first_text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," vp-date ") or contains(concat(" ",normalize-space(@class)," ")," news-release-date ")]');
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s*\|\s*(\d{2}):(\d{2}))?/u', $value, $m)) {
            $hasTime = isset($m[4]);
            $date = \DateTimeImmutable::createFromFormat('!d.m.Y H:i', "$m[1].$m[2].$m[3] " . ($hasTime ? "$m[4]:$m[5]" : '12:00'), $zone) ?: null;
        } elseif (preg_match('/(\d{1,2})\.\s+(\S+)\s+(\d{4})/u', $value, $m)) {
            $months = ['jaanuar','veebruar','märts','aprill','mai','juuni','juuli','august','september','oktoober','november','detsember'];
            $month = array_search($m[2], $months, true);
            if ($month !== false) $date = \DateTimeImmutable::createFromFormat('!j.n.Y H:i', $m[1].'.'.($month+1).'.'.$m[3].' 12:00', $zone) ?: null;
        }
    }
    $lead = first_text($xpath, '//*[contains(@class,"field--name-field-lead-text")]');
    if (!$lead) $lead = first_text($xpath, '//article//*[contains(@class,"text-block")]//p');
    if (!$lead) $lead = plain_text($meta['og:description'] ?? $meta['description'] ?? '');
    $words = preg_split('/\s+/u', $lead, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $excerpt = implode(' ', array_slice($words, 0, 24)) . (count($words)>24 ? '…' : '');
    $image = !empty($meta['og:image']) ? absolute_url($meta['og:image'], $item['url']) : null;
    if(!$image){$img=$xpath->query('//article//img[@src]')->item(0);if($img){$image=absolute_url($img->getAttribute('src'),$item['url']);$meta['og:image:alt']=$img->getAttribute('alt');}}
    return array_merge($item, ['title'=>$title, 'date'=>$date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'), 'hasTime'=>$hasTime, 'category'=>news_category($title), 'excerpt'=>$excerpt, 'image'=>$image, 'imageAlt'=>plain_text($meta['og:image:alt'] ?? ''), 'previewVersion'=>2]);
}

function excerpt(string $text): string {
    $text=preg_replace('~<(script|style)\b[^>]*>[\s\S]*?</\1>~i','',$text);
    $text=plain_text($text);$words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    return implode(' ',array_slice($words,0,24)).(count($words)>24?'…':'');
}

function remote_get(string $url): string {
    for($attempt=0;$attempt<2;$attempt++){
    $response=\wp_safe_remote_get($url,['timeout'=>8,'redirection'=>3,'limit_response_size'=>3*1024*1024,'user-agent'=>'VOL-Uudistekoond/1.0 (+https://vol.ee/)','headers'=>['Accept'=>'application/rss+xml,application/xml,application/json,text/html']]);
    if(!\is_wp_error($response) && !in_array(\wp_remote_retrieve_response_code($response),[408,429,500,502,503,504],true))break;
    }
    if(\is_wp_error($response))throw new \RuntimeException($response->get_error_message());
    $code=\wp_remote_retrieve_response_code($response);
    if($code!==200)throw new \RuntimeException('Allikas vastas HTTP '.$code);
    $body=\wp_remote_retrieve_body($response);if($body==='')throw new \RuntimeException('Allikas tagastas tühja vastuse.');return $body;
}


function collect_rss(array $source,int $count): array {
    $xml=remote_get($source['feed']);
    if(stripos($xml,'<!DOCTYPE')!==false)throw new \RuntimeException('RSS sisaldab keelatud dokumenditüüpi.');
    $doc=new \DOMDocument();$previous=libxml_use_internal_errors(true);
    try {$ok=$doc->loadXML($xml,LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);}
    finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    if(!$ok)throw new \RuntimeException('RSS ei ole korrektne XML.');
    $xp=new \DOMXPath($doc);$rows=$xp->query('/rss/channel/item');
    if(!$rows || !$rows->length)throw new \RuntimeException('RSS-ist ei leitud uudiseid.');
    $items=[];
    foreach($rows as $row){
        if(count($items)>=100)break;
        $get=static function(string $name)use($xp,$row): string {return trim((string)$xp->evaluate('string(./'.$name.')',$row));};
        $title=$get('title');$url=absolute_url($get('link'),$source['url']);$date=$get('pubDate');
        if(!$url || !$title || !strtotime($date))continue;
        $text=$get('description');$image=null;$alt='';
        foreach($xp->query('./*[local-name()="content" or local-name()="thumbnail" or local-name()="enclosure"][@url]',$row) as $media){
            $candidate=allowed_image($media->getAttribute('url'),$source['url']);
            if($candidate){$image=$candidate;break;}
        }
        if(!$image && $text){
            $html=html_document($text);$img=$html->query('//img[@src]')->item(0);
            if($img){$image=allowed_image($img->getAttribute('src'),$source['url']);$alt=$img->getAttribute('alt');}
        }
        $item=['title'=>$title,'url'=>$url,'date'=>gmdate('c',strtotime($date)),'hasTime'=>true,'excerpt'=>excerpt($text),'image'=>$image,'imageAlt'=>$alt];
        try {$item=validate_item($item,$source);}catch(\RuntimeException){continue;}
        $items[$url]=$item;
    }
    usort($items,static fn($a,$b)=>strtotime($b['date'])<=>strtotime($a['date']));
    $items=array_slice($items,0,$count);
    if(!$items)throw new \RuntimeException('RSS-is puuduvad korrektsed uudised.');
    foreach($items as &$item){
        if($item['image'])continue;
        try {
            $key='voluk_rss_article_'.md5($item['url']);$details=\get_transient($key);
            if(!is_array($details)){$details=news_details(remote_get($item['url']),$item);\set_transient($key,$details,15*60);}
            $item['image']=allowed_image($details['image']??null,$source['url']);
            $item['imageAlt']=bounded_text($details['imageAlt']??'',500);
            if(!$item['excerpt'])$item['excerpt']=bounded_text($details['excerpt']??'',2000);
        }catch(\Throwable){/* RSS uudis jääb alles ka pildilehe vea korral. */}
    }
    unset($item);
    return array_map(static fn($item)=>validate_item($item,$source),$items);
}

function collect_source(array $source,int $count): array {
    if($source['type']==='rss')return collect_rss($source,$count);
    if($source['type']==='wordpress'){
        $url=rtrim($source['url'],'/').'/wp-json/wp/v2/posts?per_page='.$count.'&status=publish&orderby=date&order=desc&_embed=wp%3Afeaturedmedia';
        $rows=json_decode(remote_get($url),true);
        if(!is_array($rows)||array_values($rows)!==$rows||count($rows)>$count||isset($rows['code']))throw new \RuntimeException('Uudiste liidese vastus ei ole korrektne.');
        $items=[];
        foreach($rows as $row){
            $row=checked_wp_row($row);
            if(($row['status']??'publish')!=='publish'||empty($row['link'])||empty($row['title']['rendered']))continue;
            $link=absolute_url($row['link'],$source['url']);
            if(!$link||preg_replace('/^www\./','',parse_url($link,PHP_URL_HOST))!==preg_replace('/^www\./','',parse_url($source['url'],PHP_URL_HOST)))continue;
            $image=$row['_embedded']['wp:featuredmedia'][0]['source_url']??null;
            $alt=$row['_embedded']['wp:featuredmedia'][0]['alt_text']??'';
            $text=$row['excerpt']['rendered']??'';
            if(!$text)$text=$row['content']['rendered']??'';
            if(!$image && !empty($row['content']['rendered'])){
                $xp=html_document($row['content']['rendered']);$img=$xp->query('//img[@src]')->item(0);
                if($img){$image=$img->getAttribute('src');$alt=$img->getAttribute('alt');}
            }
            $date=null;
            try{if(!empty($row['date_gmt'])&&$row['date_gmt']!=='0000-00-00T00:00:00')$date=(new \DateTimeImmutable($row['date_gmt'],new \DateTimeZone('UTC')))->format('c');}catch(\Exception){}
            $title=plain_text($row['title']['rendered']);
            $items[]=['title'=>$title,'url'=>$link,'date'=>$date,'hasTime'=>true,'category'=>news_category($title),'excerpt'=>excerpt($text),'image'=>$image?absolute_url($image,$source['url']):null,'imageAlt'=>plain_text($alt)];
        }
        return array_map(static fn($item)=>validate_item($item,$source),array_slice($items,0,$count));
    }
    if(!class_exists('\DOMDocument'))throw new \RuntimeException('Serveris puudub DOM/XML laiendus.');
    $html=remote_get($source['url']);
    $links=news_links($html,$source,max(6,$count));
    if(count($links)<min(3,$count))throw new \RuntimeException('Leiti liiga vähe uudiseid; allika struktuur vajab kontrolli.');
    $items=[];
    foreach($links as $link){
        $key='voluk_article_'.md5($link['url']);
        $item=\get_transient($key);
        if(!is_array($item)){
            $body=remote_get($link['url']);$item=news_details($body,$link);
            if(!$item['date'])throw new \RuntimeException('Uudise avaldamiskuupäeva ei õnnestunud tuvastada.');
            \set_transient($key,$item,15*60);
        }
        $items[]=$item;
    }
    usort($items,static fn($a,$b)=>(strtotime($b['date']??'')?:0)<=>(strtotime($a['date']??'')?:0) ?: ($a['order']??0)<=>($b['order']??0));
    return array_slice($items,0,$count);
}

