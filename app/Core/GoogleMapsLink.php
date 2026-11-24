<?php
declare(strict_types=1);
namespace KMM\Core;
/** External Google Maps SEARCH link: never geocodes or infers an exact worksite. */
final class GoogleMapsLink {
    public static function url(?string $address):?string {
        $a=trim((string)$address);
        if((function_exists('mb_strlen')?mb_strlen($a):strlen($a))<5||(function_exists('mb_strlen')?mb_strlen($a):strlen($a))>180||preg_match('/(?:未確認|未収録|不明|非公開|未定|複数拠点)/u',$a))return null;
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($a);
    }
    public static function button(?string $address):string {
        $url=self::url($address);if($url===null)return '';
        return ' <a class="btn ghost map-search-link" target="_blank" rel="noopener noreferrer nofollow external" href="'.Security::h($url).'">マップで見る</a>';
    }
}
