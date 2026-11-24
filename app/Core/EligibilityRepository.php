<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** A self-attested private checklist, never a recruitment eligibility judgment. */
final class EligibilityRepository {
    public static function normalized(mixed $keys):array {
        if(!is_array($keys)||count($keys)>count(JobEligibility::QUESTIONS))throw new \InvalidArgumentException('Invalid checklist');
        $clean=[];
        foreach($keys as $k){
            if(!is_string($k)||!array_key_exists($k,JobEligibility::QUESTIONS))throw new \InvalidArgumentException('Unknown eligibility item');
            $clean[$k]=true;
        }
        return array_keys($clean);
    }
    public static function checked(PDO $db,int $uid,string $jobId):array {
        if($uid<1)throw new \InvalidArgumentException('Login required');
        $q=$db->prepare('SELECT question_key FROM user_job_eligibility_checks WHERE user_id=? AND job_id=? ORDER BY question_key');
        $q->execute([$uid,BusinessData::id($jobId)]);
        return array_column($q->fetchAll(PDO::FETCH_ASSOC),'question_key');
    }
    public static function save(PDO $db,int $uid,string $jobId,mixed $keys):void {
        if($uid<1)throw new \InvalidArgumentException('Login required');
        $jobId=BusinessData::id($jobId);$keys=self::normalized($keys);
        // Fail closed: this view may not be used to track a hidden or retired job.
        if(!BusinessRepository::read($db,'jobs',$jobId,true))throw new \InvalidArgumentException('Job not publicly available');
        $db->beginTransaction();
        try{
            $q=$db->prepare('DELETE FROM user_job_eligibility_checks WHERE user_id=? AND job_id=?');$q->execute([$uid,$jobId]);
            $q=$db->prepare('INSERT INTO user_job_eligibility_checks(user_id,job_id,question_key) VALUES (?,?,?)');
            foreach($keys as $key)$q->execute([$uid,$jobId,$key]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
