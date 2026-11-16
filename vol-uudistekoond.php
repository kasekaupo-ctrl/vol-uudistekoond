<?php
/**
 * Plugin Name: Viljandimaa uudistekoond
 * Description: Viljandimaa nelja omavalitsuse ja VOL-i piltidega ajalehevaade. Lühikood: [vol_uudised].
 * Version: 1.1.7
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Viljandimaa Omavalitsuste Liit
 * License: GPL-2.0-or-later
 * Text Domain: vol-uudistekoond
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/includes/collector.php';

final class VOL_Uudistekoond {
    const VERSION='1.1.7';
    const OPTION='voluk_settings';
    const HOOK='voluk_refresh_source';

    public static function sources(): array {
        return [
            'vol'=>['id'=>'vol','name'=>'Omavalitsuste Liit','full_name'=>'Viljandimaa Omavalitsuste Liit','url'=>home_url('/'),'type'=>'local'],
            'viljandi-linn'=>['id'=>'viljandi-linn','name'=>'Viljandi linn','url'=>'https://viljandi.ee/','type'=>'wordpress'],
            'viljandi-vald'=>['id'=>'viljandi-vald','name'=>'Viljandi vald','url'=>'https://viljandivald.ee/','type'=>'rss','feed'=>'https://viljandivald.ee/rss-feeds/rss.xml'],
            'mulgi'=>['id'=>'mulgi','name'=>'Mulgi vald','url'=>'https://mulgivald.ee/','type'=>'wordpress'],
            'pohja-sakala'=>['id'=>'pohja-sakala','name'=>'Põhja-Sakala vald','url'=>'https://pohja-sakala.ee/','type'=>'rss','feed'=>'https://pohja-sakala.ee/rss-feeds/rss.xml'],
        ];
    }
    public static function settings(): array {
        return wp_parse_args(get_option(self::OPTION,[]),['interval'=>60,'per_source'=>3,'vol_category'=>0,'enabled'=>array_keys(self::sources()),'show_toolbar'=>true,'title'=>'Viljandimaa uudised']);
    }
    public static function init(): void {
        add_filter('cron_schedules',[__CLASS__,'cron_schedule']);
        add_action(self::HOOK,[__CLASS__,'refresh_source'],10,1);
        add_action(self::HOOK.'_once',[__CLASS__,'refresh_source'],10,1);
        add_action('init',[__CLASS__,'ensure_schedule']);
        add_action('admin_menu',[__CLASS__,'admin_menu']);
        add_action('admin_init',[__CLASS__,'register_settings']);
        add_action('admin_post_voluk_refresh',[__CLASS__,'admin_refresh']);
        add_action('update_option_'.self::OPTION,[__CLASS__,'settings_changed'],10,2);
        add_action('wp_enqueue_scripts',[__CLASS__,'register_assets']);
        add_shortcode('vol_uudised',[__CLASS__,'shortcode']);
    }
    public static function cron_schedule(array $schedules): array {
        $minutes=(int)self::settings()['interval'];
        $schedules['voluk_interval']=['interval'=>$minutes*60,'display'=>'Viljandimaa uudised: '.$minutes.' min'];
        return $schedules;
    }
    public static function ensure_schedule(): void {
        $settings=self::settings();$offset=0;
        foreach(self::sources() as $id=>$source){
            if($source['type']==='local')continue;
            if(!in_array($id,$settings['enabled'],true)){wp_clear_scheduled_hook(self::HOOK,[$id]);continue;}
            if(!wp_next_scheduled(self::HOOK,[$id]))wp_schedule_event(time()+10+$offset,'voluk_interval',self::HOOK,[$id]);
            $offset+=20;
        }
    }
    public static function activate(): void {
        if(!class_exists('DOMDocument')){deactivate_plugins(plugin_basename(__FILE__));wp_die('Viljandimaa uudistekoond vajab PHP DOM/XML laiendust. Palu veebimajutajal see sisse lülitada.');}
        if(get_option(self::OPTION,false)===false)add_option(self::OPTION,self::settings(),'','no');
        self::ensure_schedule();
    }
    public static function deactivate(): void {foreach(self::sources() as $id=>$s){wp_clear_scheduled_hook(self::HOOK,[$id]);wp_clear_scheduled_hook(self::HOOK.'_once',[$id]);}}
    public static function settings_changed($old,$new): void {self::deactivate();self::ensure_schedule();}

    public static function refresh_source(string $id): void {
        $sources=self::sources();$settings=self::settings();
        if(!isset($sources[$id])||$sources[$id]['type']==='local'||!in_array($id,$settings['enabled'],true))return;
        $key='voluk_lock_'.$id;$token=wp_generate_uuid4();$now=time();
        $existing=get_option($key,[]);
        if($existing && (int)($existing['expires']??0)<$now)delete_option($key);
        if(!add_option($key,['token'=>$token,'expires'=>$now+180],'','no'))return;
        $old=get_option('voluk_source_'.$id,[]);
        try {
            @set_time_limit(120);
            $items=\VOLUK\collect_source($sources[$id],(int)$settings['per_source']);
            if(!$items)throw new RuntimeException('Allikast ei leitud ühtegi uudist.');
            update_option('voluk_source_'.$id,['items'=>$items,'checkedAt'=>gmdate('c'),'successAt'=>gmdate('c'),'status'=>'ok','error'=>null],false);
        } catch(Throwable $e){
            $old['checkedAt']=gmdate('c');$old['status']='error';$old['error']=sanitize_text_field($e->getMessage());$old['items']=$old['items']??[];
            update_option('voluk_source_'.$id,$old,false);
        } finally {
            $current=get_option($key,[]);if(($current['token']??'')===$token)delete_option($key);
        }
    }
    public static function local_items(int $count): array {
        $settings=self::settings();$args=['post_type'=>'post','post_status'=>'publish','has_password'=>false,'posts_per_page'=>$count,'orderby'=>'date','order'=>'DESC','ignore_sticky_posts'=>true,'no_found_rows'=>true,'suppress_filters'=>false];
        if($settings['vol_category'])$args['cat']=(int)$settings['vol_category'];
        $query=new WP_Query($args);$items=[];
        foreach($query->posts as $post){
            $image=get_the_post_thumbnail_url($post,'large')?:null;$alt='';
            $attachment=get_post_thumbnail_id($post);if($attachment)$alt=(string)get_post_meta($attachment,'_wp_attachment_image_alt',true);
            if(!$image&&preg_match('/<img[^>]+src=["\']([^"\']+)/i',$post->post_content,$m))$image=esc_url_raw($m[1],['https','http']);
            $text=$post->post_excerpt?:strip_shortcodes($post->post_content);
            $items[]=['title'=>get_the_title($post),'url'=>get_permalink($post),'date'=>get_post_time('c',true,$post),'hasTime'=>true,'category'=>\VOLUK\news_category(get_the_title($post)),'excerpt'=>\VOLUK\excerpt($text),'image'=>$image,'imageAlt'=>$alt];
        }
        return $items;
    }
    public static function data(): array {
        $settings=self::settings();$result=[];
        foreach(self::sources() as $id=>$source){
            if(!in_array($id,$settings['enabled'],true))continue;
            $state=$source['type']==='local'?['items'=>self::local_items((int)$settings['per_source']),'status'=>'ok','successAt'=>gmdate('c'),'checkedAt'=>gmdate('c')]:get_option('voluk_source_'.$id,[]);
            $result[]=array_merge($source,$state,['items'=>array_slice($state['items']??[],0,(int)$settings['per_source'])]);
        }
        return $result;
    }
    public static function register_assets(): void {
        wp_register_style('voluk-news',plugins_url('assets/news.css',__FILE__),[],self::VERSION);
        wp_register_script('voluk-news',plugins_url('assets/news.js',__FILE__),[],self::VERSION,true);
    }
    public static function shortcode($atts=[]): string {
        $settings=self::settings();$atts=shortcode_atts(['allikad'=>'','arv'=>'30','vaade'=>'ajaleht','filtrid'=>!empty($settings['show_toolbar'])?'jah':'ei','pealkiri'=>$settings['title']],$atts,'vol_uudised');
        $allowed=array_keys(self::sources());$selected=$atts['allikad']!==''?array_intersect(array_map('trim',explode(',',$atts['allikad'])),$allowed):$settings['enabled'];
        $data=array_values(array_filter(self::data(),static fn($source)=>in_array($source['id'],$selected,true)));
        $items=[];foreach($data as $source)foreach($source['items'] as $item)$items[]=array_merge($item,['source_id'=>$source['id'],'source_name'=>$source['name']]);
        usort($items,static fn($a,$b)=>(strtotime($b['date']??'')?:0)<=>(strtotime($a['date']??'')?:0));
        $items=array_slice($items,0,max(1,min(100,(int)$atts['arv'])));
        self::register_assets();wp_enqueue_style('voluk-news');wp_enqueue_script('voluk-news');
        // Lühikood võib paikneda vidinas või lehekoostajas pärast wp_head'i.
        ob_start();if(!wp_style_is('voluk-news','done'))wp_print_styles('voluk-news');
        $id=wp_unique_id('voluk-');$view=$atts['vaade']==='omavalitsused'?'grouped':'newspaper';
        $topics=array_unique(array_column($items,'category'));sort($topics,SORT_LOCALE_STRING);
        ?>
        <section id="<?php echo esc_attr($id); ?>" class="voluk" data-view="<?php echo esc_attr($view); ?>" aria-label="Viljandimaa uudistekoond">
          <div class="voluk-heading"><div><span class="voluk-kicker">KOHALIK ELU</span><?php if($atts['pealkiri']!==''):?><h2><?php echo esc_html($atts['pealkiri']);?></h2><?php endif;?></div><span class="voluk-count" role="status" aria-live="polite"><?php echo count($items);?> uudist</span></div>
          <?php if($atts['filtrid']!=='ei'):?>
          <div class="voluk-toolbar"><label class="voluk-search"><span class="voluk-sr">Otsi uudiseid</span><input type="search" placeholder="Otsi uudist…" data-voluk-search></label>
          <label><span class="voluk-sr">Vali allikas</span><select data-voluk-source><option value="">Kõik allikad</option><?php foreach($data as $source):?><option value="<?php echo esc_attr($source['id']);?>"><?php echo esc_html($source['name']);?></option><?php endforeach;?></select></label>
          <label><span class="voluk-sr">Vali teema</span><select data-voluk-topic><option value="">Kõik teemad</option><?php foreach($topics as $topic):?><option><?php echo esc_html($topic);?></option><?php endforeach;?></select></label>
          <div class="voluk-views" aria-label="Vaate valik"><button type="button" data-voluk-view="newspaper" aria-pressed="<?php echo $view==='newspaper'?'true':'false';?>">Ajaleht</button><button type="button" data-voluk-view="grouped" aria-pressed="<?php echo $view==='grouped'?'true':'false';?>">Allikate kaupa</button></div></div>
          <?php endif;?>
          <div class="voluk-grid">
          <?php foreach($items as $index=>$item): if($index===0 && $view==='newspaper'):?><section class="voluk-fresh" aria-label="Kõige värskemad"><h3 class="voluk-fresh-title">Kõige värskemad</h3><div class="voluk-fresh-grid"><?php endif; echo self::card($item,false,($view==='grouped' || $index<4)?'h4':'h3'); if($view==='newspaper' && $index===min(3,count($items)-1)):?></div></section><?php endif; endforeach;?>
          </div>
          <div class="voluk-empty" <?php if($items):?>hidden<?php endif;?>><?php echo $items?'Valitud filtritega uudiseid ei leitud.':'Uudiseid pole veel kogutud. Esimene taustauuendus käivitub pärast plugina aktiveerimist; haldur saab selle käivitada ka seadistustest.';?></div>

          <?php if(current_user_can('manage_options')):$errors=array_filter($data,static fn($s)=>($s['status']??'')==='error');if($errors):?><p class="voluk-admin-note">Halduri teade: <?php echo esc_html(implode(', ',array_column($errors,'name')));?> uuendamine vajab kontrolli. Vaata Seaded → Viljandimaa uudised. Külastajatele kuvatakse viimased säilinud uudised.</p><?php endif;endif;?>
        </section>
        <?php return ob_get_clean();
    }
    private static function card(array $item,bool $lead,string $level='h3'): string {
        $sources=self::sources();$base=$sources[$item['source_id']]['url']??home_url('/');$item['image']=\VOLUK\allowed_image($item['image']??null,$base);
        $stamp=strtotime($item['date']??'')?:0;$format=!empty($item['hasTime'])?'d.m.Y H:i':'d.m.Y';
        ob_start();?>
        <article class="voluk-story<?php echo $lead?' voluk-lead':'';?>" data-source="<?php echo esc_attr($item['source_id']);?>" data-source-name="<?php echo esc_attr($item['source_name']);?>" data-topic="<?php echo esc_attr($item['category']);?>">
          <div class="voluk-image<?php echo empty($item['image'])?' voluk-no-image':'';?>">
          <?php if(!empty($item['image'])):?><img src="<?php echo esc_url($item['image']);?>" alt="<?php echo esc_attr($item['imageAlt']??'');?>" loading="<?php echo $lead?'eager':'lazy';?>" decoding="async" referrerpolicy="no-referrer"><span class="voluk-photo-credit">Foto: algallikas</span><?php else:?><span class="voluk-fallback"><?php echo esc_html($item['source_name']);?></span><?php endif;?>
          <span class="voluk-latest" <?php if(!$lead):?>hidden<?php endif;?>>VÄRSKEIM</span></div>
          <div class="voluk-body"><div class="voluk-meta"><span class="voluk-source-name"><?php echo esc_html($item['source_name']);?></span><?php if($stamp):?><time datetime="<?php echo esc_attr($item['date']);?>"><?php echo esc_html(wp_date($format,$stamp,new DateTimeZone('Europe/Tallinn')));?></time><?php endif;?></div>
          <span class="voluk-topic"><?php echo esc_html($item['category']);?></span><<?php echo $level;?>><a class="voluk-story-link" href="<?php echo esc_url($item['url']);?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['title']);?><span class="voluk-sr"> (avaneb uuel vahelehel)</span></a></<?php echo $level;?>>
          <?php if(!empty($item['excerpt'])):?><p class="voluk-excerpt"><?php echo esc_html($item['excerpt']);?></p><?php endif;?>
          <span class="voluk-read" aria-hidden="true">Loe algallikast ↗</span></div>
        </article>
        <?php return ob_get_clean();
    }
    public static function admin_menu(): void {add_options_page('Viljandimaa uudised','Viljandimaa uudised','manage_options','voluk-news',[__CLASS__,'admin_page']);}
    public static function register_settings(): void {register_setting('voluk_group',self::OPTION,['type'=>'array','sanitize_callback'=>[__CLASS__,'sanitize'],'default'=>self::settings()]);}
    public static function sanitize($input): array {
        $old=self::settings();$input=is_array($input)?$input:[];$interval=(int)($input['interval']??60);
        return ['show_toolbar'=>!empty($input['show_toolbar']),'title'=>sanitize_text_field($input['title']??$old['title']),'interval'=>in_array($interval,[15,30,60,180,360],true)?$interval:60,'per_source'=>max(1,min(10,(int)($input['per_source']??3))),'vol_category'=>absint($input['vol_category']??0),'enabled'=>array_values(array_intersect(array_map('sanitize_key',(array)($input['enabled']??[])),array_keys(self::sources())))];
    }
    public static function admin_refresh(): void {
        if(!current_user_can('manage_options'))wp_die('Puudub õigus uudiseid uuendada.');check_admin_referer('voluk_refresh');
        $id=sanitize_key($_POST['source']??'all');$sources=self::sources();$ids=$id==='all'?array_keys($sources):[$id];
        foreach($ids as $key){if(!isset($sources[$key])||$sources[$key]['type']==='local')continue;self::refresh_source($key);}
        wp_safe_redirect(add_query_arg(['page'=>'voluk-news','refreshed'=>'1'],admin_url('options-general.php')));exit;
    }
    public static function admin_page(): void {
        if(!current_user_can('manage_options'))return;$s=self::settings();?>
        <div class="wrap"><h1>Viljandimaa uudistekoond</h1><p>Lisa esilehele või mistahes alamlehele lühikood <code>[vol_uudised]</code>. Sobib WordPressi lühikoodiplokki, Elementor Shortcode-vidinasse ja klassikalisse redaktorisse.</p>
        <?php if(isset($_GET['queued'])):?><div class="notice notice-success"><p>Uuendamine on taustale järjekorda lisatud. Laadi see seadistusleht mõne aja pärast uuesti.</p></div><?php endif;?>
        <?php if(isset($_GET['refreshed'])):?><div class="notice notice-info"><p>Uuendamiskatse on lõpetatud. Vaata allpool allikate seisu ja viimase eduka kontrolli aega. Kui aeg ei muutunud, võis sama allika uuendamine juba käia.</p></div><?php endif;?>
        <form method="post" action="options.php"><?php settings_fields('voluk_group');?><table class="form-table"><tr><th>Vaikimisi pealkiri</th><td><input class="regular-text" name="<?php echo self::OPTION;?>[title]" value="<?php echo esc_attr($s['title']);?>"></td></tr><tr><th>Uudiseid igast allikast</th><td><input type="number" min="1" max="10" name="<?php echo self::OPTION;?>[per_source]" value="<?php echo (int)$s['per_source'];?>"></td></tr><tr><th>Uuendamise intervall</th><td><select name="<?php echo self::OPTION;?>[interval]"><?php foreach([15,30,60,180,360] as $n):?><option value="<?php echo $n;?>" <?php selected($s['interval'],$n);?>><?php echo $n;?> minutit</option><?php endforeach;?></select></td></tr><tr><th>VOL-i uudiste rubriik</th><td><?php wp_dropdown_categories(['name'=>self::OPTION.'[vol_category]','selected'=>$s['vol_category'],'show_option_all'=>'Kõik avalikud postitused','hide_empty'=>false,'hierarchical'=>true]);?><p class="description">VOL-i postitused loetakse otse sellest WordPressist. Vali uudiste rubriik, kui teistes rubriikides on muud sisu.</p></td></tr><tr><th>Otsing ja filtrid</th><td><label><input type="checkbox" name="<?php echo self::OPTION;?>[show_toolbar]" value="1" <?php checked(!empty($s['show_toolbar']));?>> Näita otsingut, allika- ja teemafiltreid ning vaatevalikut</label><p class="description">Üksiku koondvaate riba saab peita lühikoodiga [vol_uudised filtrid="ei"].</p></td></tr><tr><th>Nähtavad allikad</th><td><?php foreach(self::sources() as $id=>$source):?><label style="display:block;margin-bottom:8px"><input type="checkbox" name="<?php echo self::OPTION;?>[enabled][]" value="<?php echo esc_attr($id);?>" <?php checked(in_array($id,$s['enabled'],true));?>><?php echo esc_html($source['full_name']??$source['name']);?></label><?php endforeach;?></td></tr></table><?php submit_button();?></form>
        <h2>Allikate seis</h2><table class="widefat striped"><thead><tr><th>Allikas</th><th>Uudiseid</th><th>Viimane katse</th><th>Viimane edukas kontroll</th><th>Seis</th><th>Tegevus</th></tr></thead><tbody><?php foreach(self::sources() as $id=>$source):$row=$source['type']==='local'?['items'=>self::local_items((int)$s['per_source']),'status'=>'local']:get_option('voluk_source_'.$id,[]);?><tr><td><?php echo esc_html($source['name']);?></td><td><?php echo count($row['items']??[]);?></td><td><?php echo !empty($row['checkedAt'])?esc_html(wp_date('d.m.Y H:i:s',strtotime($row['checkedAt']),new DateTimeZone('Europe/Tallinn'))):'—';?></td><td><?php echo !empty($row['successAt'])?esc_html(wp_date('d.m.Y H:i',strtotime($row['successAt']),new DateTimeZone('Europe/Tallinn'))):'—';?></td><td><?php echo esc_html($source['type']==='local'?'Otse WordPressist':(!empty($row['error'])?$row['error']:(($row['status']??'')==='ok'?'Korras':'Ootab esimest kogumist')));?></td><td><?php if($source['type']!=='local'):?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="voluk_refresh"><input type="hidden" name="source" value="<?php echo esc_attr($id);?>"><?php wp_nonce_field('voluk_refresh');?><button class="button" type="submit">Uuenda</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table>
        <h2>Kasutusnäited</h2><p><code>[vol_uudised]</code></p><p><code>[vol_uudised arv="6" pealkiri="Värskeimad uudised"]</code> — lühem vaade esilehele.</p><p><code>[vol_uudised allikad="viljandi-linn,viljandi-vald,mulgi,pohja-sakala" vaade="omavalitsused"]</code> — omavalitsused eraldi rühmades.</p><p><code>[vol_uudised allikad="vol" filtrid="ei"]</code> — ainult liidu uudised.</p>
        <p>WP-Cron käivitub veebikülastuste toel. Täpseks taustauuenduseks lisa majutuses serveri cron, mis käivitab WordPressi cron’i iga 5 minuti järel. Uudiseid ei impordita WordPressi postitusteks. Allika vea korral jääb alles viimati edukalt kogutud sisu.</p>
        <p>Viljandi linn ja Mulgi vald: ametlik WordPressi uudiste liides. Viljandi vald ja Põhja-Sakala vald: ametlik RSS-voog. Puuduv pilt otsitakse uudise algallika lehelt. Fotod kuvatakse algallikast.</p></div>
        <?php
    }
}
VOL_Uudistekoond::init();
register_activation_hook(__FILE__,['VOL_Uudistekoond','activate']);
register_deactivation_hook(__FILE__,['VOL_Uudistekoond','deactivate']);
