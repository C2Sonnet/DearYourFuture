<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Explicit, audited gate for retrying a strict composite feed after TWO prior
 * independently reviewed seed batches. No approval is inferred from source text.
 * The composite key is stable per pair of immutable batch IDs; a new operator
 * ticket cannot silently create a second attempt for the same review pair.
 */
final class SeedCompositeReview {
    public static function plan(array $feed,string $companyBatchId,string $entityBatchId,string $ticket):array {
        $companyBatchId=BusinessData::id($companyBatchId);
        $entityBatchId=BusinessData::id($entityBatchId);
        if($companyBatchId===$entityBatchId)throw new \InvalidArgumentException('Distinct approved origin batches required');
        if(!preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/D',$ticket))throw new \InvalidArgumentException('Traceable review ticket required');
        $feed['event_key']='review_'.substr(hash('sha256',$companyBatchId.':'.$entityBatchId),0,40);
        SeedCompositeRepository::validate($feed);
        return ['feed'=>$feed,'company_batch_id'=>$companyBatchId,'entity_batch_id'=>$entityBatchId,'review_ticket'=>$ticket];
    }
    /** Re-check the actual actor from the database, not a client-supplied role. */
    public static function requireReviewer(PDO $db,int $actor):void {
        if(!$db->inTransaction()||$actor<1)throw new \DomainException('Reviewer transaction required');
        $q=$db->prepare('SELECT u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
        $q->execute([$actor]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='active'||!in_array($row['role'],['ADMIN','FOUNDER','DEVELOPER'],true)){
            throw new \DomainException('Active manager authorization required for reviewed import');
        }
    }
    /** Call while the caller owns the transaction; row locks serialize review-state checks.
     * This is NOT a substitute for inspecting the human decisions on each conflict.
     */
    public static function assertReviewed(PDO $db,array $plan):void {
        if(!$db->inTransaction())throw new \RuntimeException('Review preflight requires a transaction');
        $source=SeedRepository::validate([
            ...$plan['feed']['company_batch'],
            'event_key'=>'review_validate_source',
        ]);
        $resolvedTotal=0;
        foreach(['company_batch_id','entity_batch_id'] as $key){
            $id=$plan[$key];
            $q=$db->prepare('SELECT id,status,seed_version,source_document FROM seed_update_batches WHERE id=? FOR UPDATE');
            $q->execute([$id]);$batch=$q->fetch(PDO::FETCH_ASSOC);
            if(!$batch||$batch['status']!=='complete'
                ||$batch['seed_version']!==$source['version']
                ||$batch['source_document']!==$source['source_document']){
                throw new \RuntimeException('Reviewed batch missing, pending or provenance differs');
            }
            foreach(['seed_merge_conflicts','seed_entity_conflicts'] as $table){
                $q=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id=? AND status='pending'");
                $q->execute([$id]);
                if((int)$q->fetchColumn()>0)throw new \RuntimeException('Unresolved origin conflicts; restart denied');
                $q=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id=? AND status='resolved' AND resolver_id IS NOT NULL");
                $q->execute([$id]);$resolvedTotal+=(int)$q->fetchColumn();
            }
        }
        if($resolvedTotal<1)throw new \RuntimeException('No recorded conflict decision for reviewed restart');
    }
}
