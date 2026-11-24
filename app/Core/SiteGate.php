<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class SiteGate {
    public static function isRoute(string $path): bool {return $path==='/site-access'||$path==='/site-access/check';}
    public static function enabled(PDO $db): bool {
        $value=Database::settings($db,'site_gate.enabled');
        if(!is_bool($value))throw new \RuntimeException('Invalid site gate setting');
        return $value;
    }
    public static function passed(PDO $db): bool {
        if(!self::enabled($db))return true;
        $revision=Database::settings($db,'site_gate.revision');
        if(!is_int($revision))throw new \RuntimeException('Invalid site gate revision');
        return (int)($_SESSION['gate_revision']??-1)===$revision;
    }
    public static function handle(PDO $db,string $path,string $method):void {
        if(!self::enabled($db)){header('Location: /',true,303);return;}
        if($method==='POST' && $path==='/site-access/check'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::render('安全な操作が必要です',403);return;}
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            if(!Throttle::allow('site-gate:'.$ip,6,900)){self::render('操作回数を超えました',429);return;}
            $password=(string)($_POST['gate_password']??'');$hash=Database::settings($db,'site_gate.password_hash');
            if(!is_string($hash)||$hash===''||!password_verify($password,$hash)){self::render('アクセスパスワードを確認してください',403);return;}
            session_regenerate_id(true);
            $_SESSION['gate_revision']=(int)Database::settings($db,'site_gate.revision');
            header('Location: '.Session::safeReturn((string)($_POST['next']??'/')),true,303);return;
        }
        if($method==='GET'&&$path==='/site-access'){
            $next=Session::safeReturn((string)($_GET['next']??'/'));
            $html='<section class="hero compact"><h1>サイトの共通パスワード</h1><p>このサイトの閲覧には共通パスワードの確認が必要です。ユーザー認証とは独立した保護です。</p></section>'.
                '<form class="setup-form" action="/site-access/check" method="post">'.Session::csrfField().
                '<input type="hidden" name="next" value="'.Security::h($next).'">'.
                '<label>サイト共通パスワード<input type="password" name="gate_password" required autocomplete="off"></label>'.
                '<button class="btn primary" type="submit">確認する</button></form>';
            Kernel::layout('サイトのアクセス確認',$html,false);return;
        }
        self::render('許可されない操作です',405);
    }
    private static function render(string $message,int $status):void {
        http_response_code($status);Kernel::layout('アクセス確認','<section class="hero compact"><h1>'.Security::h($message).'</h1><a class="text-link" href="/site-access">入力へ戻る</a></section>',false);
    }
    public static function isExempt(string $path): bool {
        return $path==='/account/logout'||$path==='/admin'||$path==='/admin/login'||str_starts_with($path,'/admin/')||self::isRoute($path);
    }
    public static function configure(PDO $db,bool $enabled,?string $password):void {
        $stored=Database::settings($db,'site_gate.password_hash');
        if($password!==null&&$password!==''){
            if(strlen($password)<12||strlen($password)>256)throw new \InvalidArgumentException('Site password must have 12-256 bytes');
            $stored=password_hash($password,PASSWORD_ARGON2ID);
        }
        if($enabled && (!is_string($stored)||$stored===''))throw new \InvalidArgumentException('Set a site password before enabling gate');
        $current=self::enabled($db);
        if($current===$enabled&&($password===null||$password===''))return;
        $db->beginTransaction();
        try {
            Database::setSetting($db,'site_gate.password_hash',$stored);
            Database::setSetting($db,'site_gate.enabled',$enabled);
            $revision=Database::settings($db,'site_gate.revision');
            Database::setSetting($db,'site_gate.revision',(int)$revision+1);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
