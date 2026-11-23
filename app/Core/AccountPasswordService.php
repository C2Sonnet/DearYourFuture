<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Authenticated password change; SMTP is neither a prerequisite nor an approval channel. */
final class AccountPasswordService {
    public static function validNew(string $password,string $confirm):bool {
        return $password!=='' && hash_equals($password,$confirm) && PasswordPolicy::valid($password);
    }
    public static function change(PDO $db,int $userId,string $currentPassword,string $newPassword,string $confirmation):bool {
        if($userId<1 || $currentPassword==='' || !self::validNew($newPassword,$confirmation) ||
           hash_equals($currentPassword,$newPassword) || !defined('PASSWORD_ARGON2ID'))return false;
        // Hash outside the row lock; Argon2id is intentionally expensive.
        $newHash=password_hash($newPassword,PASSWORD_ARGON2ID);
        $db->beginTransaction();
        try {
            $q=$db->prepare("SELECT password_hash FROM users WHERE id=? AND status='active' FOR UPDATE");
            $q->execute([$userId]);$oldHash=$q->fetchColumn();
            if(!is_string($oldHash) || !password_verify($currentPassword,$oldHash)){$db->rollBack();return false;}
            $q=$db->prepare("UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND status='active'");
            $q->execute([$newHash,$userId]);
            if($q->rowCount()!==1)throw new \RuntimeException('Password update raced');
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')->execute([$userId]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')
                ->execute([$userId,'account.password.changed.local','user',(string)$userId,'{}']);
            if(!$db->commit())throw new \RuntimeException('Password update commit unavailable');
            return true;
        } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
