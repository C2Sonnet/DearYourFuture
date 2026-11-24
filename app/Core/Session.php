<?php
declare(strict_types=1);
namespace KMM\Core;
final class Session {
    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }
    public static function isLoopback(): bool {
        return in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
    }
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        if (!self::isHttps() && !(PHP_SAPI === 'cli-server' && self::isLoopback())) { throw new \RuntimeException('HTTPS is required outside localhost'); }
        session_name('KMMSESSID');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>self::isHttps(), 'httponly'=>true,'samesite'=>'Strict']);
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        ini_set('session.cookie_httponly','1');
        ini_set('session.use_trans_sid','0');
        if (!session_start()) { throw new \RuntimeException('Session storage unavailable'); }
        if (!isset($_SESSION['created_at'])) { $_SESSION['created_at']=time(); }
    }
    public static function csrf(): string {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(32)); }
        return $_SESSION['csrf'];
    }
    public static function validCsrf(string $value): bool {return $value!=='' && hash_equals(self::csrf(),$value);}
    public static function csrfField(): string {return '<input type="hidden" name="csrf" value="'.Security::h(self::csrf()).'">';}
    public static function clearIdentity(): void {
        unset($_SESSION['uid'],$_SESSION['auth_version'],$_SESSION['authenticated_at'],$_SESSION['history_preview'],$_SESSION['reviewed_seed_preview'],$_SESSION['reviewed_seed_cancelled'],$_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates']);
        session_regenerate_id(true);
        unset($_SESSION['csrf'],$_SESSION['kmm_login_notice_flash_v1'],$_SESSION['kmm_night_notice_day_v1'],$_SESSION['kmm_kimi_login_pending_v2']);
    }
    public static function safeReturn(string $next): string {
        if ($next===''||strlen($next)>512||$next[0]!=='/'||str_starts_with($next,'//')||str_contains($next,'\\')||preg_match('/[\x00-\x1f\x7f]/',$next)||preg_match('/%(?:2f|5c|0d|0a|00)/i',$next))return '/';
        $path=parse_url($next,PHP_URL_PATH);
        // Redirect targets are site route paths (ASCII). A browser/proxy can normalize
        // dot segments or percent-encoded paths *after* this check, turning a seemingly
        // unrelated path into /account/login, /admin/login, or /initial and causing a
        // redirect loop. Refuse ambiguous path spellings instead of guessing which
        // layer will normalize them. Query string escapes remain allowed.
        if (!is_string($path) || str_contains($path,'%')
            || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D',$path)
            || str_starts_with($path,'/initial')||str_starts_with($path,'/admin/login')
            || str_starts_with($path,'/account/login')||str_starts_with($path,'/account/reset-password')
            || str_starts_with($path,'/account/forgot-password')) return '/';
        return $next;
    }
}
