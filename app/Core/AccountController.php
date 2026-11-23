<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class AccountController {
    public static function isRoute(string $path): bool {
        return in_array($path,['/account/login','/admin/login','/account/logout','/account/forgot-password','/account/reset-password','/account/kimi/ack'],true);
    }
    public static function handle(string $path,string $method): void {
        if($path==='/account/kimi/ack'){
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            if($method!=='POST'){header('Allow: POST');http_response_code(405);echo '{"ok":false}';return;}
            $member=Identity::current();
            if(!KimiIntro::allowed($member)){http_response_code(403);echo '{"ok":false}';return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);echo '{"ok":false}';return;}
            try{KimiIntro::acknowledge(Database::connected(),(int)$member['id']);}
            catch(\Throwable $error){error_log('KMM intro acknowledgment unavailable: '.get_class($error));http_response_code(503);echo '{"ok":false}';return;}
            echo '{"ok":true}';return;
        }
        if($path==='/account/logout'){
            if(Identity::current()===null){self::message('ログインが必要です','ログイン後に操作してください。',401);return;}
            if($method!=='POST'){header('Allow: POST');self::message('許可されない操作','ログアウトはフォームから操作してください。',405);return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::message('操作できません','入力画面を開き直してください。',403);return;}
            Identity::signOut();header('Location: /account/login',true,303);return;
        }
        if($path==='/admin/login'){self::login($method,true);return;}
        if($path==='/account/login'){self::login($method,false);return;}
        if($path==='/account/forgot-password'||$path==='/account/reset-password'){
            if(Database::settings(Database::connected(),'account.password_reset_enabled')!==true){self::message('パスワード再設定は停止中です','管理者にお問い合わせください。',404);return;}
            self::reset($path,$method);return;
        }
        self::message('ページがありません','指定した認証ページはありません。',404);
    }
    private static function login(string $method,bool $adminOnly):void {
        $next=$adminOnly?'/admin':Session::safeReturn((string)($_POST['next']??$_GET['next']??'/'));
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::message('操作をやり直してください','入力画面を開き直してください。',403);return;}
            $id=trim((string)($_POST['identifier']??''));$password=(string)($_POST['password']??'');
            if(strlen($id)>254||strlen($password)>512){self::loginForm($next,$adminOnly,'入力内容を確認してください。',401);return;}
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            if(!Throttle::allow('login:'.$ip.':'.hash('sha256',strtolower($id)),8,900)){self::message('しばらくしてからお試しください','安全のため認証回数を制限しています。',429);return;}
            $db=Database::connected();
            $stmt=$db->prepare('SELECT u.id,u.handle,u.email,u.display_name,u.password_hash,u.auth_version,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.email=? OR u.handle=? LIMIT 1');
            $stmt->execute([$id,$id]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
            $authorized=$user&&$user['status']==='active'&&password_verify($password,(string)$user['password_hash']);
            if($adminOnly&&$authorized)$authorized=in_array((string)$user['role'],['ADMIN','FOUNDER','DEVELOPER'],true);
            if(!$authorized){self::loginForm($next,$adminOnly,'ID、メールアドレス、またはパスワードが正しくありません。',401);return;}
            if(password_needs_rehash((string)$user['password_hash'],PASSWORD_ARGON2ID)){
                $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_ARGON2ID),$user['id']]);
            }
            // Read the previous successful login before inserting the new audit event.
            $previousLogin=LoginNotices::lastLogin($db,(int)$user['id']);
            $clientIp=LoginNotices::clientIp();
            Identity::signIn($user);
            LoginNotices::flashLogin($previousLogin,$clientIp);
            // Only a freshly authenticated login may show a reset letter again.
            $_SESSION['kmm_kimi_login_pending_v2']=true;
            // Last successful login is durably recorded in audit_logs (no mutable profile timestamp).
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$user['id'],'account.login','user',(string)$user['id'],json_encode(['ip'=>$clientIp,'server_at'=>date(DATE_ATOM)],JSON_THROW_ON_ERROR)]);
            MailNotifications::notify($db,(int)$user['id'],'login',['url'=>rtrim((string)Database::settings($db,'site.url'),'/').'/account/security']);
            header('Location: '.$next,true,303);return;
        }
        if($method!=='GET'){header('Allow: GET, POST');self::message('操作できません','許可された方法ではありません。',405);return;}
        self::loginForm($next,$adminOnly);
    }
    private static function loginForm(string $next,bool $adminOnly,string $error='',int $status=200):void {
        http_response_code($status);
        $action=$adminOnly?'/admin/login':'/account/login';
        $html='<section class="hero compact"><span class="eyebrow">WELCOME BACK</span><h1>おかえりなさい。</h1><p>今日も、あなたのペースで一歩ずつ。</p></section>';
        if($adminOnly)$html.='<p class="note">管理者以上の専用ログインです。サイト共通パスワードの例外ですが、アカウント認証は必須です。</p>';
        if($error!=='')$html.='<p role="alert" class="note">'.Security::h($error).'</p>';
        $html.='<form class="setup-form" method="post" action="'.$action.'">'.Session::csrfField().'<input type="hidden" name="next" value="'.Security::h($next).'">'.
            '<label>ユーザーID または メールアドレス<input name="identifier" autocomplete="username" required maxlength="254"></label>'.
            '<label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>'.
            ($adminOnly?'':'<a class="text-link" href="/account/forgot-password">パスワードは忘れましたか？</a>').
            '<button type="submit" class="btn primary">ログインする</button>';
        $html.='</form>';Kernel::layout('ログイン',$html,false);
    }
    private static function reset(string $path,string $method): void {
        if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');self::message('操作できません','許可された方法ではありません。',405);return;}
        if($method==='POST'&&!Session::validCsrf((string)($_POST['csrf']??''))){self::message('安全な操作が必要です','画面を開き直してください。',403);return;}
        $db=Database::connected();
        if($path==='/account/forgot-password'){
            $notice='';
            if($method==='POST'){
                $email=trim((string)($_POST['email']??''));$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
                if(!Throttle::allow('reset:'.$ip,8,900)||!Throttle::allow('reset-email:'.hash('sha256',strtolower($email)),4,3600)){
                    self::message('操作制限','安全のため、時間をおいてもう一度お試しください。',429);return;
                }
                if(!MailConfig::configured()){
                    self::message('メール配送を準備中です','SMTPが未設定のため、再設定メールは送信されていません。管理者にお問い合わせください。',503);return;
                }
                $q=$db->prepare("SELECT id,email FROM users WHERE email=? AND status='active' LIMIT 1");$q->execute([$email]);$user=$q->fetch(PDO::FETCH_ASSOC);
                if($user)TokenService::issue($db,$user,'reset_password');
                // Identical body for known/unknown email; delivery failures are recorded server-side.
                $notice='申請を受け付けました。登録されている場合は確認メールが届きます。届かない場合は管理者にお問い合わせください。';
            }
            $html='<section class="hero compact"><span class="eyebrow">ACCOUNT SECURITY</span><h1>パスワードの再設定</h1><p>登録メールアドレスに確認リンクを送ります。リンクは20分間、一度だけ使用できます。</p></section>';
            if($notice!=='')$html.='<p role="status" class="note">'.Security::h($notice).'</p>';
            $html.='<form class="setup-form" method="post" action="/account/forgot-password">'.Session::csrfField().'<label>登録メールアドレス<input type="email" name="email" autocomplete="email" required maxlength="254"></label><button class="btn primary" type="submit">確認メールを申請</button><a class="text-link" href="/account/login">ログイン画面へ戻る</a></form>';
            Kernel::layout('パスワード再設定',$html,false);return;
        }
        $token=(string)($_POST['token']??$_GET['token']??'');
        if($method==='POST'){
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            if(!Throttle::allow('reset-confirm:'.$ip,8,900)){self::message('操作制限','しばらくしてからお試しください。',429);return;}
            $pw=(string)($_POST['password']??'');$again=(string)($_POST['confirm']??'');
            if($pw!==$again||!TokenService::consumePassword($db,$token,'reset_password',$pw)){
                self::message('確認リンクが無効です','リンクの期限またはパスワード条件を確認してください。',422);return;
            }
            if(Identity::current()!==null)Identity::signOut();
            header('Location: /account/login?password_reset=1',true,303);return;
        }
        $valid=TokenService::inspect($db,$token,'reset_password')!==null;
        $html='<section class="hero compact"><h1>新しいパスワードを設定</h1><p>'.($valid?'8文字以上・英字／数字／記号の2種類以上で入力してください。':'このリンクは無効、または期限切れです。').'</p></section>';
        if($valid)$html.='<form class="setup-form" method="post" action="/account/reset-password">'.Session::csrfField().'<input type="hidden" name="token" value="'.Security::h($token).'">'.
            '<label>新しいパスワード<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>'.
            '<label>確認用パスワード<input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label><button class="btn primary">パスワードを変更</button></form>';
        Kernel::layout('パスワード再設定',$html,false);
    }
    private static function message(string $title,string $detail,int $code):void {
        http_response_code($code);Kernel::layout($title,'<section class="hero compact"><h1>'.Security::h($title).'</h1><p>'.Security::h($detail).'</p><a class="text-link" href="/account/login">ログインに戻る</a></section>',false);
    }
}
