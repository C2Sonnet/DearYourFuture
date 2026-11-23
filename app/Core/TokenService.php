<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** One-use, short-lived, SHA256-at-rest tokens. Raw tokens are never logged. */
final class TokenService {
    public const PURPOSES=['reset_password','password_change','email_change_approval','email_change','email_verify'];
    public static function issue(PDO $db,array $user,string $purpose,?string $newEmail=null): bool {
        if(!in_array($purpose,self::PURPOSES,true))throw new \InvalidArgumentException('Unknown token purpose');
        if(!MailConfig::configured())return false;
        $id=(int)$user['id'];$token=bin2hex(random_bytes(32));$target=$purpose==='email_change'?$newEmail:(string)$user['email'];
        if(!is_string($target)||!filter_var($target,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid recipient');
        $payload=$purpose==='email_verify'?json_encode(['email_sha256'=>EmailVerificationState::digest((string)$user['email'])],JSON_THROW_ON_ERROR):
            (in_array($purpose,['email_change_approval','email_change'],true)?json_encode(['new_email'=>$newEmail],JSON_THROW_ON_ERROR):null);
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND consumed_at IS NULL')->execute([$id,$purpose]);
            $db->prepare('INSERT INTO auth_tokens(user_id,purpose,token_hash,payload_json,expires_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 MINUTE))')->execute([$id,$purpose,hash('sha256',$token),$payload]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $url=(string)Database::settings($db,'site.url');
        $base=rtrim($url,'/');if(!preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?(?:/.*)?\z~',$base))throw new \RuntimeException('Invalid site origin');
        $route=match($purpose){'reset_password'=>'/account/reset-password','password_change'=>'/account/security','email_change'=>'/account/verify-email','email_change_approval'=>'/account/verify-email','email_verify'=>'/account/verify-email'};
        $title=match($purpose){'reset_password'=>'パスワード再設定','password_change'=>'パスワード変更の本人確認','email_change'=>'新しいメールアドレスの確認','email_change_approval'=>'現在のメールアドレスでの変更承認','email_verify'=>'新しいメールアドレスの検証'};
        $body="『キミの未来へ』の{$title}のご案内です。\n\n次のURLを20分以内に開いてください。\n{$base}{$route}?token={$token}\n\n心当たりがない場合は、このメールを無視してください。\nこのURLを第三者に渡さないでください。\n";
        try {
            SmtpMailer::send($target,'【キミの未来へ】'.$title,$body);
            self::deliveryEvent($db,$id,$purpose,'sent');
            return true;
        } catch(\Throwable $e) {
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE token_hash=?')->execute([hash('sha256',$token)]);
            self::deliveryEvent($db,$id,$purpose,'failed');
            error_log('KMM mail delivery failed: '.get_class($e));
            return false;
        }
    }
    public static function deliveryEvent(PDO $db,?int $userId,string $purpose,string $status): void {
        $stmt=$db->prepare('INSERT INTO mail_delivery_events(user_id,event_type,status) VALUES (?,?,?)');$stmt->execute([$userId,$purpose,$status]);
    }
    public static function validFormat(string $token): bool {return (bool)preg_match('/\A[a-f0-9]{64}\z/',$token);}
    public static function inspect(PDO $db,string $token,string $purpose,?int $userId=null): ?array {
        if(!self::validFormat($token)||!in_array($purpose,self::PURPOSES,true))return null;
        $stmt=$db->prepare('SELECT t.*,u.email,u.id AS target_user_id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose=? AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.status=\'active\' LIMIT 1');
        $stmt->execute([hash('sha256',$token),$purpose]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||($userId!==null&&(int)$row['user_id']!==$userId))return null;
        if($purpose==='email_verify'){
            $payload=json_decode((string)($row['payload_json']??''),true);
            if(!is_array($payload)||!is_string($payload['email_sha256']??null)||!hash_equals($payload['email_sha256'],EmailVerificationState::digest((string)$row['email'])))return null;
        }
        return $row;
    }
    public static function consumePassword(PDO $db,string $token,string $purpose,string $newPassword,?int $expectedUser=null): bool {
        if(!in_array($purpose,['reset_password','password_change'],true)||!self::validFormat($token)||!PasswordPolicy::valid($newPassword))return false;
        if(!defined('PASSWORD_ARGON2ID'))return false;
        $hash=password_hash($newPassword,PASSWORD_ARGON2ID);
        $db->beginTransaction();
        try {
            $stmt=$db->prepare('SELECT id,user_id FROM auth_tokens WHERE token_hash=? AND purpose=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $stmt->execute([hash('sha256',$token),$purpose]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$row||($expectedUser!==null&&(int)$row['user_id']!==$expectedUser)){$db->rollBack();return false;}
            $id=(int)$row['user_id'];
            $update=$db->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND status=\'active\'');
            $update->execute([$hash,$id]);
            if($update->rowCount()!==1){$db->rollBack();return false;}
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')->execute([$id]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$id,'account.password.changed','user',(string)$id,'{}']);
            $db->commit();
            self::notifySecurity($db,$id,'パスワードが変更されました','アカウントのパスワードが変更されました。心当たりがない場合は管理者へご連絡ください。');
            return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function approveEmail(PDO $db,string $token): ?array {
        if(!self::validFormat($token))return null;
        $db->beginTransaction();
        try {
            $q=$db->prepare("SELECT t.id,t.user_id,t.payload_json,u.email FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose='email_change_approval' AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.status='active' FOR UPDATE");
            $q->execute([hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){$db->rollBack();return null;}
            $payload=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            $new=(string)($payload['new_email']??'');
            if(!filter_var($new,FILTER_VALIDATE_EMAIL)){$db->rollBack();return null;}
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$row['id']]);
            $db->commit();
            return ['id'=>(int)$row['user_id'],'email'=>(string)$row['email'],'new_email'=>$new];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function consumeEmail(PDO $db,string $token): bool {
        if(!self::validFormat($token))return false;
        $db->beginTransaction();
        try {
            $q=$db->prepare("SELECT id,user_id,payload_json FROM auth_tokens WHERE token_hash=? AND purpose='email_change' AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE");
            $q->execute([hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){$db->rollBack();return false;}
            $payload=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            $email=(string)($payload['new_email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$db->rollBack();return false;}
            $id=(int)$row['user_id'];
            $old=$db->prepare('SELECT email FROM users WHERE id=?');$old->execute([$id]);$oldEmail=(string)$old->fetchColumn();
            $q=$db->prepare("UPDATE users SET email=?,auth_version=auth_version+1 WHERE id=? AND status='active'");$q->execute([$email,$id]);
            if($q->rowCount()!==1){$db->rollBack();return false;}
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')->execute([$id]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$id,'account.email.changed','user',(string)$id,'{}']);
            $db->commit();
            try {SmtpMailer::send($oldEmail,'【キミの未来へ】メールアドレス変更のお知らせ','アカウントのメールアドレスが変更されました。心当たりがない場合は管理者にご連絡ください。');}catch(\Throwable $e){error_log('KMM old-email notification failed: '.get_class($e));}
            return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Bound to the current stored address: older verification tokens cannot validate a new address. */
    public static function consumeEmailVerification(PDO $db,string $token):bool {
        if(!self::validFormat($token))return false;
        $db->beginTransaction();
        try{
            $q=$db->prepare("SELECT t.id,t.user_id,t.payload_json,u.email FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose='email_verify' AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.status='active' FOR UPDATE");
            $q->execute([hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){$db->rollBack();return false;}
            $payload=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||!is_string($payload['email_sha256']??null)||!hash_equals($payload['email_sha256'],EmailVerificationState::digest((string)$row['email']))){$db->rollBack();return false;}
            $uid=(int)$row['user_id'];
            EmailVerificationState::record($db,$uid,(string)$row['email'],true);
            $db->prepare("UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose='email_verify' AND consumed_at IS NULL")->execute([$uid]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$uid,'account.email.verified','user',(string)$uid,'{}']);
            $db->commit();return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function notifySecurity(PDO $db,int $id,string $title,string $body):void {
        try{$q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$id]);$email=$q->fetchColumn();
            if(is_string($email))SmtpMailer::send($email,'【キミの未来へ】'.$title,$body);
        }catch(\Throwable $e){error_log('KMM security notification failed: '.get_class($e));}
    }
}

