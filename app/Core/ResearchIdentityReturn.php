<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * A return-for-more-evidence audit for the d1.0.1 operator preflight.
 * NOT an identity decision, company status, publication or outbox operation.
 * The actor row lock serialises duplicate submissions by the same actor;
 * auditing a token twice never inserts a second return event.
 */
final class ResearchIdentityReturn {
    private const REASONS=['missing_evidence','identity_unverified','worksite_unverified',
        'vacancy_unverified','overlay_conflict'];
    private const EVENT='research.identity.return';
    public static function newPreview(int $actor,int $sessionVersion,string $digest,string $ticket,int $count,int $now):array {
        if($actor<1||$sessionVersion<0||$count<0||$count>27
            ||!self::sha($digest)||!self::ticket($ticket)||$now<1)throw new \DomainException('Invalid audited preflight metadata');
        return ['actor'=>$actor,'auth_version'=>$sessionVersion,'digest'=>$digest,'ticket'=>$ticket,
            'nonce'=>bin2hex(random_bytes(32)),'until'=>$now+600,'count'=>$count];
    }
    public static function validPreview(mixed $p,int $actor,int $sessionVersion,int $now):bool {
        return is_array($p)&&count($p)===7&&$actor>0&&$sessionVersion>=0
            &&($p['actor']??null)===$actor&&($p['auth_version']??null)===$sessionVersion
            &&self::sha($p['digest']??null)&&self::sha($p['nonce']??null)
            &&self::ticket($p['ticket']??null)&&is_int($p['count']??null)
            &&$p['count']>=0&&$p['count']<=27&&is_int($p['until']??null)
            &&$now>0&&$now<=$p['until']&&$p['until']-$now<=600;
    }
    private static function sha(mixed $v):bool {
        return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;
    }
    private static function ticket(mixed $v):bool {
        return is_string($v)&&preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/D',$v)===1;
    }
    /** True when already returned; read from persistent audit history, never session inference. */
    public static function status(PDO $db,array $actor,int $sessionVersion,array $preview,int $now):bool {
        return self::transaction($db,$actor,$sessionVersion,$preview,null,$now,false);
    }
    /** True if a new return was written, false if exact token was already returned. */
    public static function record(PDO $db,array $actor,int $sessionVersion,array $preview,string $reason,int $now):bool {
        if(!in_array($reason,self::REASONS,true))throw new \DomainException('Unlisted return reason');
        return self::transaction($db,$actor,$sessionVersion,$preview,$reason,$now,true);
    }
    private static function transaction(PDO $db,array $actor,int $sessionVersion,array $preview,?string $reason,int $now,bool $write):bool {
        $id=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($id)||$id<1||!is_string($role)||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)
            ||!self::validPreview($preview,$id,$sessionVersion,$now))
            throw new \DomainException('Invalid or expired return review');
        if($db->inTransaction())throw new \RuntimeException('Nested return transaction');
        $db->beginTransaction();
        try {
            $s=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||($row['status']??null)!=='active'
                ||($row['role']??null)!==$role||(int)($row['auth_version']??-1)!==$sessionVersion)
                throw new \DomainException('Administrative permission was revoked');
            $key='return:'.$preview['nonce'];
            $q=$db->prepare('SELECT details_json FROM audit_logs WHERE actor_id=? AND event_type=? AND target_type=? AND target_key=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$id,self::EVENT,'research_delta',$key]);
            $found=$q->fetch(PDO::FETCH_ASSOC);
            if($found!==false){
                if(!is_array($found)||!is_string($found['details_json']??null))throw new \DomainException('Invalid audit record');
                $prior=json_decode($found['details_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($prior)||($prior['upload_sha256']??null)!==$preview['digest']
                    ||($prior['operator_ticket']??null)!==$preview['ticket']
                    ||($prior['research_version']??null)!=='d1.0.1')throw new \DomainException('Return token collision or inconsistent audit');
                $db->commit();return !$write;
            }
            if(!$write){$db->commit();return false;}
            // In particular: no raw URL, applicant name, review note or evidence text in the audit event.
            $details=json_encode(['research_version'=>'d1.0.1','operator_ticket'=>$preview['ticket'],
                'upload_sha256'=>$preview['digest'],'proposals'=>$preview['count'],
                'reason_code'=>$reason,'result'=>'returned_for_evidence_not_approved'],JSON_THROW_ON_ERROR);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$id,self::EVENT,'research_delta',$key,$details]);
            $db->commit();return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
