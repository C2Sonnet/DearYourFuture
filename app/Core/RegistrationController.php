<?php
declare(strict_types=1);
namespace KMM\Core;

use PDO;

/** Optional public registration, default OFF, with idempotent MinaHelper greeting. */
final class RegistrationController {
    public const PATH='/account/register';
    public const SETTING='auth.self_registration_enabled';
    public static function enabled(PDO $db):bool {
        // Older installations may not contain this opt-in key. Missing means OFF.
        try {return Database::settings($db,self::SETTING)===true;}
        catch (\RuntimeException $e) {return false;}
    }
    public static function isRoute(string $path):bool{return $path===self::PATH;}
    public static function handle(string $method):void {
        $db=Database::connected();
        if(!self::enabled($db)){http_response_code(404);Kernel::layout('登録停止中','<p>現在、ユーザーの自己登録は受け付けていません。管理者にお問い合わせください。</p>',false);return;}
        if(Identity::current()!==null){header('Location: /account/center',true,303);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::error(403,'入力画面を開き直してください。');return;}
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            if(!Throttle::allow('self-signup:'.$ip,3,3600)){self::error(429,'登録操作が多すぎます。時間を置いてください。');return;}
            $handle=trim((string)($_POST['handle']??''));
            $email=strtolower(trim((string)($_POST['email']??'')));
            $display=trim((string)($_POST['display_name']??''));
            $password=(string)($_POST['password']??'');$confirmation=(string)($_POST['password_confirm']??'');
            if(!preg_match('/\A[a-z][a-z0-9_]{2,39}\z/D',$handle)||ReservedAccounts::reserved($handle)
                ||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254
                ||$display===''||mb_strlen($display)>120||!AccountPasswordService::validNew($password,$confirmation)){
                self::error(422,'ID・メール・表示名・パスワード条件を確認してください。');return;
            }
            $hash=password_hash($password,PASSWORD_ARGON2ID);
            unset($password,$confirmation);
            $avatar=null;$commitAttempted=false;
            try{
                $db->beginTransaction();
                // The setting is locked and revalidated to prevent sign-up after the operator disables it.
                $setting=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? FOR UPDATE');
                $setting->execute([self::SETTING]);
                $value=$setting->fetchColumn();
                if(!is_string($value)||json_decode($value,true)!==true)throw new \RuntimeException('Registration disabled');
                $db->prepare("INSERT INTO users(handle,email,display_name,password_hash,status) VALUES (?,?,?,?,'active')")
                    ->execute([$handle,$email,$display,$hash]);
                $userId=(int)$db->lastInsertId();
                $avatar=InitialAvatar::create($handle,$display);
                $db->prepare('INSERT INTO user_profiles(user_id,avatar_file) VALUES (?,?)')->execute([$userId,$avatar]);
                $db->prepare("INSERT INTO user_roles(user_id,role_code) VALUES (?,'USER')")->execute([$userId]);
                $db->prepare('INSERT INTO message_user_settings(user_id,allow_direct,public_profile,notify_email) VALUES (?,1,0,1)')->execute([$userId]);
                WelcomeMessage::enqueue($db,$userId,$display);
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                    ->execute([$userId,'account.self_registered','user',(string)$userId,'{}']);
                $commitAttempted=true;
                if(!$db->commit())throw new \RuntimeException('Registration commit failed');
            }catch(\Throwable $e){
                if($db->inTransaction())try{$db->rollBack();}catch(\Throwable){}
                if(!$commitAttempted && is_string($avatar))InitialAvatar::discard($avatar);
                error_log('KMM self-registration unavailable: '.get_class($e));
                self::error(409,'登録を完了できませんでした。入力または登録済みIDを確認してください。');return;
            }
            header('Location: /account/login?registered=1',true,303);return;
        }
        if($method!=='GET'){header('Allow: GET, POST');self::error(405,'許可されない方法です。');return;}
        $html='<section class="hero compact"><h1>ユーザー登録</h1><p>企業・求人の検討や応募状況を、自分専用の記録として残せます。登録するとMinaHelperからサイト内の歓迎メッセージが届きます。</p></section>'
             .'<form class="setup-form" method="post" action="/account/register">'.Session::csrfField()
             .'<label>ユーザーID<input name="handle" required pattern="[a-z][a-z0-9_]{2,39}" autocomplete="username" maxlength="40"></label>'
             .'<label>表示名<input name="display_name" required maxlength="120"></label>'
             .'<label>メールアドレス<input type="email" name="email" required maxlength="254" autocomplete="email"></label>'
             .'<label>パスワード（8文字以上・2種類以上の文字種）<input type="password" name="password" required minlength="8" maxlength="256" autocomplete="new-password"></label>'
             .'<label>確認パスワード<input type="password" name="password_confirm" required minlength="8" maxlength="256" autocomplete="new-password"></label>'
             .'<p class="note">登録するメールの受信確認はこの画面では行いません。本人以外のメールを使用しないでください。</p>'
             .'<button type="submit" class="btn primary">アカウントを登録</button></form>';
        header('Cache-Control: no-store, private');Kernel::layout('ユーザー登録',$html,false);
    }
    private static function error(int $status,string $message):void {http_response_code($status);Kernel::layout('登録できません','<div role="alert">'.Security::h($message).'</div>',false);}
}
