<?php
declare(strict_types=1);
namespace KMM\Core;
final class Security {
    public static function h(?string $text): string {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function headers(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: '.(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/map'?'strict-origin-when-cross-origin':'no-referrer'));
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        $isMap=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/map';
        if($isMap){
            // Maps API requires Google scripts, tiles, and runtime map styling. Route scoped.
            header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self' https://maps.googleapis.com https://maps.gstatic.com; img-src 'self' data: blob: https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com https://*.google.com; connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com https://*.google.com; frame-src https://www.google.com; font-src 'self' https://fonts.gstatic.com; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
        }elseif(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/site-resources'){
            header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
        }elseif(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/'){
            header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
        }else{header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");}
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, private, no-transform');
    }
    public static function previewAllowed(): bool {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return PHP_SAPI === 'cli-server' && getenv('KMM_PREVIEW_MODE') === '1' && in_array($ip,['127.0.0.1','::1'], true);
    }
}
