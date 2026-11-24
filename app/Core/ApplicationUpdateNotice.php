<?php
declare(strict_types=1);
namespace KMM\Core;

use PDO;

/**
 * Non-destructive release reconciliation for user-owned application tracks.
 * Invoked INSIDE the updater's data transaction while the site is in maintenance.
 * A change to the employer/job MUST NOT delete an applicant's record or change its status.
 */
final class ApplicationUpdateNotice {
    private const PREFIX = '【システム更新】';

    /** Locks current application tracks; returned values intentionally exclude private notes. */
    public static function capture(PDO $db): array {
        $q=$db->query('SELECT a.id,a.user_id,a.status,a.company_id,a.job_id,a.revision,a.archived_at,
            c.display_name AS company_name,c.visibility AS company_visibility,c.revision AS company_revision,
            j.title AS job_title,j.recruitment_state AS job_state,j.deadline_on,j.archived_at AS job_archived,
            j.revision AS job_revision
            FROM application_tracks a JOIN companies c ON c.id=a.company_id
            LEFT JOIN jobs j ON j.id=a.job_id FOR UPDATE');
        $out=[];
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $id=(string)$r['id'];
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($out[$id]))throw new \RuntimeException('Application tracking key corrupted');
            if(count($out)>=100000)throw new \RuntimeException('Too many application records to safely reconcile');
            $out[$id]=$r;
        }
        return $out;
    }

    /** Index only source IDs and strictly comparison-relevant fields, never arbitrary private details. */
    private static function sourceIndex(?array $catalogue,string $kind):array {
        $ids=[];
        if(!is_array($catalogue))return $ids;
        $field=$kind==='companies'?'company_id':'job_id';
        foreach(($catalogue[$kind]??[]) as $r){
            if(!is_array($r)||!is_string($r[$field]??null))continue;
            $ids[$r[$field]]=$r;
        }
        return $ids;
    }
    /** Deterministic source UUID mapping. Name-based guesses are prohibited. */
    private static function crosswalk(PDO $db):array {
        $out=['companies'=>[],'jobs'=>[]];
        $q=$db->query("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE record_type IN ('companies','jobs') LIMIT 10000");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $kind=(string)$r['record_type'];$base=json_decode((string)$r['base_json'],true);
            if(!isset($out[$kind])||!is_array($base))continue;
            $source=$base[$kind==='companies'?'source_company_id':'source_job_id']??null;
            if(is_string($source))$out[$kind][(string)$r['record_id']]=$source;
        }
        return $out;
    }
    private static function selected(array $item,string $kind):array {
        $fields=$kind==='companies'?
            ['legal_name','brand','recruitment_status','headquarters_recheck_r07','company_details','identity_evidence_url','company_official_urls']:
            ['job_title','company_id','employment_type','publication_status','screening_status','recruitment_year','worksite','recheck_r07','r07_batch50_user_requested_public_delta','deadline_on','application_deadline','salary','salary_details','working_hours','job_details','source_url'];
        $out=[];
        foreach($fields as $key)if(array_key_exists($key,$item))$out[$key]=$item[$key];
        return $out;
    }
    private static function isExpired(?string $date):bool {
        if($date===null||preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date)!==1)return false;
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Tokyo'));
        return $dt!==false && $dt < new \DateTimeImmutable('today',new \DateTimeZone('Asia/Tokyo'));
    }
    /** Same transaction as the signed business DML; rolls back on ANY invariant failure. */
    public static function reconcile(PDO $db,array $before,?array $oldCatalogue,?array $newCatalogue,string $version):array {
        if(!$db->inTransaction())throw new \LogicException('Update reconciliation must be inside database transaction');
        $after=self::capture($db);
        $mapped=self::crosswalk($db);
        $oldC=self::sourceIndex($oldCatalogue,'companies');$newC=self::sourceIndex($newCatalogue,'companies');
        $oldJ=self::sourceIndex($oldCatalogue,'jobs');$newJ=self::sourceIndex($newCatalogue,'jobs');
        $affected=0;$critical=0;$unchanged=0;
        $insert=$db->prepare('INSERT INTO application_events(id,track_id,event_kind,title,memo,event_on,event_time) VALUES (?, ?, ?, ?, ?, UTC_DATE(), UTC_TIME())');
        foreach($before as $id=>$prev){
            $next=$after[$id]??null;
            // ALL application states, not only successful ones, must retain their owner and history.
            if($next===null || (int)$next['user_id']!==(int)$prev['user_id'] ||
                (string)$next['company_id']!==(string)$prev['company_id'] ||
                (string)($next['job_id']??'')!==(string)($prev['job_id']??'') ||
                (string)$next['status']!==(string)$prev['status'] ||
                (string)($next['archived_at']??'')!==(string)($prev['archived_at']??'')){
                throw new \RuntimeException('User-owned selection state changed during update; rollback required');
            }
            $companySource=$mapped['companies'][$prev['company_id']]??null;
            $jobSource=$prev['job_id']!==null?($mapped['jobs'][$prev['job_id']]??null):null;
            $messages=[];$flag='information_updated';
            if((string)$prev['company_name']!==(string)$next['company_name'] ||
               (string)$prev['company_visibility']!==(string)$next['company_visibility'] ||
               (int)$prev['company_revision']!==(int)$next['company_revision'])$messages[]='企業の登録情報が変更されました。';
            if($prev['job_id']!==null && ((string)$prev['job_title']!==(string)$next['job_title'] ||
               (string)$prev['job_state']!==(string)$next['job_state'] ||
               (string)$prev['deadline_on']!==(string)$next['deadline_on'] ||
               (string)$prev['job_archived']!==(string)$next['job_archived'] ||
               (int)$prev['job_revision']!==(int)$next['job_revision']))$messages[]='求人条件・募集状態・期限のいずれかが変更されました。';
            if($companySource!==null){
                if(!isset($newC[$companySource])){$messages[]='現在の企業データ集から対象外となりました。応募記録は保存されています。';$flag='unavailable';}
                elseif(isset($oldC[$companySource]) && $oldC[$companySource]!==$newC[$companySource])$messages[]='採用済み企業データ集が更新されました。';
            }
            if($jobSource!==null){
                if(!isset($newJ[$jobSource])){$messages[]='現在の求人データ集から対象外となりました。応募記録は保存されています。';$flag='unavailable';}
                elseif(isset($oldJ[$jobSource]) && $oldJ[$jobSource]!==$newJ[$jobSource])$messages[]='採用済み求人データ集が更新されました。';
            }
            if(($next['company_visibility']??'')==='archived' || ($next['job_archived']??null)!==null){
                $messages[]='掲載がアーカイブされました。個人の選考履歴は引き続き確認できます。';$flag='unavailable';
            }
            $deadline=$next['job_id']!==null?(is_string($next['deadline_on']??null)?$next['deadline_on']:null):null;
            if(self::isExpired($deadline)){$messages[]='求人の掲載期限（'.$deadline.'）を過ぎています。現在の応募・選考可否は必ず原典で確認してください。';$flag='deadline_expired';}
            if($messages===[]){$unchanged++;continue;}
            $affected++;
            if(in_array($prev['status'],['screening','accepted'],true)){
                $critical++;
                $messages[]='この応募は「'.ApplicationData::LABELS[$prev['status']].'」として保全され、選考状況は自動変更されません。';
            }else{
                $messages[]='現在の応募状況は変更していません。原典の内容を確認し、必要ならご本人が更新してください。';
            }
            $text=implode(' ',array_unique($messages));
            if(mb_strlen($text)>2800)$text=mb_substr($text,0,2800);
            $eventId=bin2hex(random_bytes(16));
            $insert->execute([$eventId,$id,'other',self::PREFIX.' '.($flag==='deadline_expired'?'期限を確認':($flag==='unavailable'?'掲載状態を確認':'情報変更を確認')),$text]);
        }
        return ['notified_tracks'=>$affected,'protected_in_progress_or_accepted'=>$critical,'unchanged_tracks'=>$unchanged];
    }
    /** Latest update events for the signed-in user only. */
    public static function forUser(PDO $db,int $userId,array $trackIds):array {
        if($userId<1 || !$trackIds)return [];
        $ids=[];foreach($trackIds as $t){$id=is_array($t)?($t['id']??null):$t;if(is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id))$ids[$id]=true;}
        if(!$ids)return [];
        $mark=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$db->prepare('SELECT e.track_id,e.title,e.memo,e.created_at FROM application_events e JOIN application_tracks a ON a.id=e.track_id WHERE a.user_id=? AND e.track_id IN ('.$mark.') AND e.event_kind=\'other\' AND e.title LIKE ? AND e.deleted_at IS NULL ORDER BY e.created_at DESC,e.id DESC LIMIT 200');
        $stmt->execute(array_merge([$userId],array_keys($ids),[self::PREFIX.'%']));
        $out=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(string)$row['track_id'];if(!isset($out[$id]))$out[$id]=$row;}
        return $out;
    }
    /** UIkit alert for an affected application. Values are escaped; status never auto-overwritten. */
    public static function alert(array $track,?array $event=null):string {
        $status=(string)($track['status']??'');
        $deadline=is_string($track['deadline_on']??null)?$track['deadline_on']:null;
        $expired=self::isExpired($deadline);
        if($event===null && !$expired)return '';
        $important=in_array($status,['screening','accepted'],true);
        $class=$expired||$important?'uk-alert-warning':'uk-alert-primary';
        $message='';
        if($expired)$message.='掲載されている期限（'.$deadline.'）を過ぎています。選考中・合格の記録はそのまま保持されます。 '; 
        if($event!==null)$message.=(string)($event['memo']??'情報が更新されました。');
        if($message==='')return '';
        return '<div uk-alert class="uk-alert '. $class .'" role="alert"><strong>求人・企業情報のお知らせ</strong><p>'.Security::h($message).'</p></div>';
    }
}
