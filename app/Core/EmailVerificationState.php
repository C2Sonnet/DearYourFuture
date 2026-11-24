<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Verification status is attached to the exact current email, never to an account in perpetuity. */
final class EmailVerificationState {
    private static function key(int $uid):string {return 'account.email_verification.v1.'.$uid;}
    public static function digest(string $email):string {return hash('sha256',strtolower(trim($email)));}
    public static function status(PDO $db,int $uid,string $email):string {
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');
        $q->execute([self::key($uid)]);$raw=$q->fetchColumn();
        if(!is_string($raw))return 'unrecorded';
        $data=json_decode($raw,true);
        if(!is_array($data)||!isset($data['email_sha256'])||!is_string($data['email_sha256'])||!hash_equals($data['email_sha256'],self::digest($email)))return 'unverified';
        return ($data['verified']??false)===true?'verified':'unverified';
    }
    /** Call only inside the surrounding DB transaction. */
    public static function record(PDO $db,int $uid,string $email,bool $verified):void {
        $value=json_encode(['email_sha256'=>self::digest($email),'verified'=>$verified,'verified_at'=>$verified?gmdate('Y-m-d\TH:i:s\Z'):null],JSON_THROW_ON_ERROR);
        $db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')
           ->execute([self::key($uid),$value]);
    }
}
