<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Read-only, page-bounded operator visibility for the unapproved internal review queue.
 * All records are d1.0.1 and contain NO company/job IDs, URLs, user overlay or applicant data.
 * Requires DB-active role/auth_version on *each* request; never trusts the session alone.
 */
final class ResearchIdentityReviewBoard {
    public const VERSION='d1.0.1';
    public static function page(mixed $raw):int {
        if(is_int($raw))$raw=(string)$raw;
        if(!is_string($raw)||!preg_match('/\A[1-9][0-9]{0,3}\z/D',$raw))
            throw new \InvalidArgumentException('Invalid private queue page');
        $n=(int)$raw;
        if($n<1||$n>1000)throw new \InvalidArgumentException('Private queue page out of range');
        return $n;
    }
    public static function listing(PDO $db,array $actor,int $authVersion,int $page):array {
        $page=self::page($page);
        $id=$actor['id']??null;$role=$actor['role']??null;
        if(!is_int($id)||$id<1||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$authVersion<0)
            throw new \DomainException('Private research review queue requires an administrator');
        if($db->inTransaction())throw new \RuntimeException('Read-only queue cannot join another transaction');
        $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=?');
        $q->execute([$id]);$actual=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($actual)||($actual['status']??null)!=='active'
            ||($actual['role']??null)!==$role||(int)($actual['auth_version']??-1)!==$authVersion)
            throw new \DomainException('Administrator rights changed; sign in again');
        // The SQL constants are constructed from a strictly bounded server-side integer.
        $offset=($page-1)*25;
        $q=$db->prepare('SELECT review_id,source_record_sha256,state,reason_code,revision,last_actor_id,updated_at FROM research_identity_review_states WHERE research_version=? ORDER BY updated_at DESC,review_id ASC LIMIT 26 OFFSET '.$offset);
        $q->execute([self::VERSION]);$rawRows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!is_array($rawRows)||count($rawRows)>26)throw new \DomainException('Invalid shared queue response');
        $hasNext=count($rawRows)===26;
        $items=[];
        foreach(array_slice($rawRows,0,25) as $r){
            if(!is_array($r)||!is_string($r['review_id']??null)
                ||preg_match('/\Ar-[a-f0-9]{24}\z/D',$r['review_id'])!==1
                ||!is_string($r['source_record_sha256']??null)
                ||preg_match('/\A[a-f0-9]{64}\z/D',$r['source_record_sha256'])!==1
                ||!in_array($r['state']??null,['evidence_review','needs_evidence'],true)
                ||(int)($r['revision']??0)<1||(int)($r['revision']??0)>2147483646
                ||(int)($r['last_actor_id']??0)<1
                ||(($r['state']==='needs_evidence')!==($r['reason_code']!==null))
                ||($r['reason_code']!==null&&!in_array($r['reason_code'],[
                    'missing_evidence','identity_unverified','worksite_unverified','vacancy_unverified','overlay_conflict'],true)))
                throw new \DomainException('Corrupted research review row; operator investigation required');
            // Important: do NOT expose upload digests, research text, source URLs or user details.
            $items[]=['review_id'=>$r['review_id'],'source_sha256'=>$r['source_record_sha256'],
                'state'=>$r['state'],'reason_code'=>$r['reason_code'],
                'revision'=>(int)$r['revision'],'last_actor_id'=>(int)$r['last_actor_id']];
        }
        return ['version'=>self::VERSION,'items'=>$items,'page'=>$page,'has_next'=>$hasNext,
            'approved'=>false,'published'=>false,'notification_queued'=>false];
    }
}
