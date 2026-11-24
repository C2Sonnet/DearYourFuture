<?php
declare(strict_types=1);
namespace KMM\Core;
/** Fail-closed access decisions, independent from the rendering layer. */
final class AccessPolicy {
    public const DEFAULTS=[
        'site_gate.enabled'=>false,
        'site_gate.password_hash'=>null,
        'site_gate.revision'=>1,
        'access.guest_browsing_enabled'=>false,
        'account.password_reset_enabled'=>true,
        'auth.self_registration_enabled'=>false,
        'messages.blocking_for_members'=>false,
        'site.countdown.enabled'=>true,
        'site.countdown.ends_at'=>'2026-11-23T16:00:00+09:00'
    ];
    public static function allowedAnonymousAuth(string $path,string $method,bool $resetOn=true): bool {
        return match ($path) {
            '/account/login' => in_array($method,['GET','POST'],true),
            '/account/forgot-password' => $resetOn && in_array($method,['GET','POST'],true),
            '/account/reset-password' => $resetOn && in_array($method,['GET','POST'],true),
            default => false,
        };
    }
    public static function publicPage(string $path): bool {
        return in_array($path,['/','/companies','/companies/compare','/map','/jobs','/library','/library/sources','/activity','/guide','/site-resources'],true)
            || preg_match('~\A/companies/[a-f0-9]{32}(?:/locations|/map|/library|/activity)?\z~D',$path)===1
            || preg_match('~\A/library/[a-f0-9]{32}(?:/download)?\z~D',$path)===1
            || preg_match('~\A/jobs/[a-f0-9]{32}\z~D',$path)===1;
    }
    public static function decision(string $path,string $method,bool $signedIn,bool $guestBrowsing,bool $resetOn=true): string {
        if (self::allowedAnonymousAuth($path,$method,$resetOn))return 'allow';
        if(str_starts_with($path,'/messages')||str_starts_with($path,'/users/'))return $signedIn?'allow':'login';
        if (!$signedIn) return $guestBrowsing && $method==='GET' && self::publicPage($path)?'allow':'login';
        if (str_starts_with($path,'/admin'))return 'admin-check';
        return 'allow';
    }
    public static function canChangeGuestBrowsing(string $role): bool {return in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true);}
}
