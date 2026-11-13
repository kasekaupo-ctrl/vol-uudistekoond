# Viljandimaa uudistekoond 1.1.4

WordPressi plugin VOL-i veebilehele. Kuvab Viljandi linna, Viljandi valla, Mulgi valla, Põhja-Sakala valla ning paigaldatud WordPressi enda (VOL-i) uudiseid. Vaikimisi kolm uudist igast allikast, kokku kuni 15 lugu.

## Paigaldamine

1. Ava WordPressis **Pluginad → Lisa uus plugin → Laadi plugin üles**.
2. Vali `vol-uudistekoond-1.1.4.zip`, paigalda ja aktiveeri.
3. Ava **Seaded → Viljandimaa uudised**.
4. Vali VOL-i uudiste rubriik, kui kõik WordPressi postitused ei ole uudised. Vaikimisi kasutatakse kõiki avalikke postitusi.
5. Lisa vajalikule lehele **Lühikood / Shortcode** plokk ja sisesta `[vol_uudised]`.

Elementoris kasuta **Shortcode**-vidinat. Klassikalises redaktoris sobib lühikood otse lehe sisusse. Esilehel tuleb muuta seda lehte, mis on valitud menüüs **Seaded → Lugemine → Staatiline esileht**. Kui esileht on ehitatud teema mallina, lisa lühikood vastavasse malli lühikoodiploki või lehekoostaja abil.

Plugin ei muuda aktiveerimisel automaatselt olemasolevat esilehte ega teema kujundust. Sama lühikoodi võib kasutada mitmel lehel või mitu korda ühel lehel. Kujundus on piiratud uudistekoondi elementidega.

## Lühikoodid

Kõik viis allikat, ajalehevaade:

```text
[vol_uudised]
```

Kompaktsem esilehe plokk, kuus kõige värskemat lugu kõigi lubatud allikate peale kokku:

```text
[vol_uudised arv="6" pealkiri="Värskeimad uudised"]
```

Kõigi nelja omavalitsuse uudised eraldi rühmades:

```text
[vol_uudised allikad="viljandi-linn,viljandi-vald,mulgi,pohja-sakala" vaade="omavalitsused"]
```

Ainult VOL-i uudised:

```text
[vol_uudised allikad="vol" pealkiri="Omavalitsuste Liidu uudised" filtrid="ei"]
```

Pealkirjata plokk:

```text
[vol_uudised pealkiri=""]
```

Lühikoodi allikate tunnused:

| Tunnus | Allikas |
| --- | --- |
| `vol` | Selle WordPressi enda avalikud postitused; VOL-i paigaldusel liidu uudised |
| `viljandi-linn` | Viljandi linn |
| `viljandi-vald` | Viljandi vald |
| `mulgi` | Mulgi vald |
| `pohja-sakala` | Põhja-Sakala vald |

`arv` piirab kogu ploki uudiste arvu (1–100), mitte uudiste arvu igast omavalitsusest. Iga allika kogutava uudiste arvu ülempiiri määrad seadistustes (1–10). Kui omavalitsuse avalehel on vähem sobivaid uudiselinke, kuvatakse seal leiduvad lood. Kui allikas on üldseadistustes välja lülitatud, ei lülita lühikood seda eraldi sisse.

`filtrid="ei"` peidab otsingu, allika ja teema valiku ning vaate vahetamise nupud. `vaade="ajaleht"` on vaikimisi; alternatiiv on `vaade="omavalitsused"`.

## Automaatne uuendamine

- Välisallikaid uuendab WordPressi WP-Cron. Vaikimisi kord 60 minuti järel; valida saab 15, 30, 60, 180 või 360 minutit.
- Igal välisallikal on eraldi ajastatud töö, et ühe allika tõrge ei peataks ülejäänuid.
- Esimene kogumine pannakse aktiveerimisel taustale. Veebikülastuste olemasolul täitub koond tavaliselt mõne minuti jooksul.
- Seadistuslehel saab allika kogumise nupuga **Uuenda** taustale lisada.
- VOL-i postitused loetakse WordPressist vaate moodustamisel; neid ei pea välisest veebist uuesti koguma.
- Väliseid uudiseid ei lisata WordPressi postitusteks ega meediateeki. See väldib dubleerimist ja uudisvoo saastamist.
- Kui allikas ei vasta, säilivad viimased edukalt kogutud uudised. Viga ja viimase õnnestunud kontrolli aeg on haldurile näha seadistuslehel.

WP-Cron sõltub tavaliselt veebikülastustest. Täpseks taustatööks ka vaiksel veebilehel lisa serveri cron, näiteks iga 5 minuti järel:

```cron
*/5 * * * * /usr/bin/php /home/KASUTAJA/public_html/wp-cron.php >/dev/null 2>&1
```

Asenda teed oma majutuse tegelike teedega. Kui serveri cron on seadistatud ja kontrollitud, võib lisada `wp-config.php` faili enne WordPressi käivitavat rida:

```php
define('DISABLE_WP_CRON', true);
```

Ära lülita WP-Cron’i välja enne toimiva serveri cron’i seadistamist. Plugin ise ei muuda seda seadistust.

**Lehe vahemälu:** kui VOL-i veebileht kasutab täislehe vahemälu või CDN-i, määra koondiga lehe vahemälu eluiga kuni uuendusintervalli pikkuseks või välista see leht vahemälust. Muidu võib serveris juba uuendatud koond jõuda külastajani hiljem. Ka VOL-i uue postituse nähtavus sõltub lehe vahemälust.

## Andmeallikad ja sisu

Viljandi linna ja Mulgi valla uudised loetakse ametlikust WordPressi REST-liidesest kuupäeva kahanevas järjekorras. Viljandi valla ja Põhja-Sakala valla puhul loetakse ametliku avalehe uudiselingid, kontrollitakse artiklite avaldamisaegu ning valitakse leitud lugudest värskeimad. Avalehe valik ei pruugi hõlmata kogu uudistearhiivi.

Vaikimisi on suurim lugu koondi kõige värskem uudis. Algallika kellaaega arvestatakse, kui see on olemas. Ainult kuupäevaga lugude täpne järjestus sama päeva sees ei ole teada.

Iga loo juures kuvatakse allikas, kuupäev ja olemasolul kellaaeg, pilt ning lühike sissejuhatus kuni neljal real. Kui algallika sissejuhatus on lühem, on ka väljavõte lühem. Pilt laaditakse algallikast; pildi puudumisel või laadimistõrke korral kuvatakse allika nimega tekstikaart. Fotode autorite info ja kogu tekst jäävad algallikasse.

Teema määratakse pealkirja märksõnade järgi ning võib vajada sisulist täpsustamist.

## Nõuded

- WordPress 6.2 või uuem.
- PHP 8.0 või uuem ning DOM/XML laiendus. Kasuta majutuses hooldatud PHP versiooni.
- WordPressi HTTP API peab saama teha väljuvaid HTTPS-päringuid nelja omavalitsuse veebilehtedele.
- Brauseris JavaScript filtrite ja vaate vahetamise jaoks. Ilma JavaScriptita jääb uudiste ajalehevaade loetavaks.

Kasutatakse WordPressi tavapäraseid seadistusi, postituste päringut, HTTP API-t ja cron’i. Eraldi Node.js-i, andmebaasitabeleid, API-võtmeid ega ChatGPT ühendust pole vaja.

## Kontrollitud

Pluginat katsetati kohalikus WordPressis 7.1.4 ja PHP 8.4.27-ga. Kontrolliti aktiveerimist, kõigi nelja omavalitsuse pärisandmete kogumist, kohalikke VOL-i uudiseid, 15 loo koondit, lühikoodi allikafiltreid, uudiste arvu, mitut koondit samal lehel, seadistuste valideerimist, cron’i lisamist ja eemaldamist ning varasema sisu säilitamist ühenduse vea korral.

VOL-i pärisveebilehte selle paketi loomisel ei muudetud. Enne avalikul esilehel kasutamist tasub plugin paigaldada testlehele ja kontrollida sobivust aktiivse teema ning vahemäluga.

## Kujundus 1.1.4

Kujundus järgib vol.ee sinist põhivärvi #0069ba, tumesinist #2c3847, heledat tausta #f3f8fb ning Work Sansi pealkirju ja PT Serifi põhiteksti. Fonte ei laadita eraldi välisest teenusest: VOL-i teema olemasolevad fondid rakenduvad automaatselt, mujal kasutatakse varufonte. Uuendamiseks laadi uus ZIP WordPressis üles ja vali olemasoleva plugina asendamine. Lühikood ja seaded säilivad.

Versioon 1.1.4: ajalehevaate plokk „Kõige värskemad” näitab nelja kõige uuemat uudist (arvutis 2 × 2, telefonis ühes veerus). Filtreerimisel näidatakse valiku nelja värskeimat uudist.

1.1.4: suuremad abitekstid, üks fookustatav link uudisekaardi kohta, kaardi fookusraam, teavitus uuel vahelehel avanemisest ja H4 pealkirjad alajaotiste sees. Piltide kirjeldused pärinevad algallikast; puuduvate kirjeldustega plakatite teave vajab sisulist täiendamist.

1.1.4: Seaded → Viljandimaa uudised → Otsing ja filtrid võimaldab kogu tööriistariba peita. Üksiku koondvaate puhul kasuta [vol_uudised filtrid="ei"]. Atribuut filtrid="jah" näitab riba ka siis, kui see on seadistustes peidetud.

1.1.4: eemaldatud uudisteploki alumine selgitus ja selle eraldusjoon.

1.1.5: pildid lubatud ainult allika enda domeenilt ja selle www-variandilt (HTTPS, port 443). Kontroll kehtib ka varem salvestatud piltidele. Välisandmete struktuur ja väljade pikkused kontrollitakse enne salvestamist. Vigase vastuse korral säilib viimane edukas sisu. Muult domeenilt pildi asemel näidatakse allika nime. Brauseri pildipäringu võimalikku ümbersuunamist see kontroll ei piira.
