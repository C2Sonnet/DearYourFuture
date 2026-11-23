<?php
declare(strict_types=1);
namespace KMM\Core;

/** Read-only public catalog of date-stamped site background artwork. */
final class SiteResourcesController {
    private const DIR = __DIR__.'/../../public/assets/background-history';
    public static function isRoute(string $path):bool {return $path==='/site-resources';}
    public static function editions():array {
        if(!is_dir(self::DIR)||is_link(self::DIR))return [];
        $entries=scandir(self::DIR);if($entries===false)return [];
        $out=[];
        foreach($entries as $date){
            if(!is_string($date)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date))continue;
            $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Tokyo'));
            if(!$dt||$dt->format('Y-m-d')!==$date)continue;
            $dir=self::DIR.'/'.$date;
            if(!is_dir($dir)||is_link($dir))continue;
            $images=[];
            foreach(['landscape'=>'横長・パソコン用','portrait'=>'縦長・スマートフォン用'] as $kind=>$label){
                $file=$dir.'/'.$kind.'.jpg';
                if(!is_file($file)||is_link($file)||filesize($file)>12000000)continue;
                // File is a local checked-in asset; no user-provided filenames are reflected in HTML.
                $images[]=['url'=>'/assets/background-history/'.$date.'/'.$kind.'.jpg','label'=>$label];
            }
            if($images!==[])$out[$date]=$images;
        }
        krsort($out,SORT_STRING);
        return array_slice($out,0,60,true);
    }
    public static function handle(string $method):void {
        if($method!=='GET'){header('Allow: GET');http_response_code(405);Kernel::layout('サイト資源','<p>GETのみ利用できます。</p>');return;}
        $html='<section class="hero compact"><span class="eyebrow">SITE RESOURCES</span><h1>サイト資源</h1><p>歴代のサイト背景画像を日付別にまとめました。画像を選ぶと拡大表示、ダウンロードボタンから元の画像を保存できます。</p></section>';
        $groups=self::editions();
        if($groups===[])$html.='<p class="note">公開できる背景画像はまだありません。</p>';
        foreach($groups as $date=>$images){
            $html.='<section class="section site-resource-edition"><h2>'.Security::h($date).' の背景</h2><div class="site-resource-gallery" uk-lightbox="animation: fade">';
            foreach($images as $img){
                $url=Security::h($img['url']);$label=Security::h($img['label']);
                $html.='<article class="site-resource-item"><a class="site-resource-image-link" href="'.$url.'" data-caption="'.Security::h($date).'・'.$label.'" aria-label="'.$label.'を拡大表示"><img src="'.$url.'" alt="'.Security::h($date).'のサイト背景（'.$label.'）" loading="lazy"></a><div class="site-resource-item-footer"><strong>'.$label.'</strong><a class="btn ghost" href="'.$url.'" download="kimi-no-mirai-'.$date.'-'.(str_contains($img['url'],'landscape')?'landscape':'portrait').'.jpg">画像をダウンロード</a></div></article>';
            }
            $html.='</div></section>';
        }
        Kernel::layout('サイト資源',$html);
    }
}
