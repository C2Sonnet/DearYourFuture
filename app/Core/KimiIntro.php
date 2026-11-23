<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Private, account-scoped one-time invitation. Does not change Schema41. */
final class KimiIntro {
    private const ACK_EVENT='account.kimi_letter_ack_v1';
    private const ACK_STEP='__kimi_letter_ack_v1'; // Reserved; not an editable guide step.
    public static function allowed(?array $user): bool {
        if($user===null)return false;
        if(!KimiLetter::available())return false;
        $role=(string)($user['role']??'');
        $handle=(string)($user['handle']??'');
        try {
            $choice=FounderPrivacyController::override(Database::connected(),(int)($user['id']??0));
            if($choice!==null)return $choice;
        }catch(\Throwable){return false;}
        return (Rbac::RANK[$role]??0)>=Rbac::RANK['FOUNDER'] || strcasecmp($handle,'mina')===0;
    }
    public static function acknowledged(PDO $db,int $userId): bool {
        if($userId<1)throw new \InvalidArgumentException('Invalid user');
        // Unlike a retention-limited audit, user_guide_steps is owned and persistent.
        $q=$db->prepare('SELECT 1 FROM user_guide_steps WHERE user_id=? AND step_key=? LIMIT 1');
        $q->execute([$userId,self::ACK_STEP]);
        if($q->fetchColumn())return true;
        // Previous placeholder-modal acknowledgments intentionally do not suppress this real letter.
        return false;
    }
    public static function acknowledge(PDO $db,int $userId): void {
        if($userId<1)throw new \InvalidArgumentException('Invalid user');
        $db->beginTransaction();
        try{
            // Serialize the first acknowledgment for each account, including parallel tabs.
            $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
            if(!$lock->fetchColumn())throw new \RuntimeException('Account missing');
            $previouslyAcknowledged=self::acknowledged($db,$userId);
            $persist=$db->prepare('INSERT IGNORE INTO user_guide_steps(user_id,step_key) VALUES (?,?)');
            $persist->execute([$userId,self::ACK_STEP]);
            if(!$previouslyAcknowledged){
                $q=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)');
                $q->execute([$userId,self::ACK_EVENT,'user',(string)$userId,'{}']);
            }
            $db->commit();
        }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    public static function modal(bool $autoOpen): string {
        return '<div id="kimi-intro-modal" class="uk-modal kimi-uk-modal" uk-modal hidden aria-hidden="true" data-kimi-auto="'.($autoOpen?'1':'0').'" data-kimi-csrf="'.Security::h(Session::csrf()).'">'
          .'<div class="uk-modal-dialog kimi-dialog" role="dialog" aria-modal="true" aria-labelledby="kimi-intro-title" tabindex="-1">'
          .'<button class="uk-modal-close-default kimi-close" type="button" data-kimi-close aria-label="閉じる">×</button>'
          .'<div class="kimi-modal-content kimi-letter-scroll" data-kimi-content>'.KimiLetter::html().'</div>'
          .'</div></div>';
    }
}
