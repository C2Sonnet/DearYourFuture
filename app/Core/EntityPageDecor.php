<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Authenticated-only provenance of personal application status, without altering research records. */
final class EntityPageDecor {
    public const ORDER=['consideration'=>1,'applied'=>2,'rejected'=>3,'screening'=>4,'accepted'=>5];
    public static function best(array $statuses):?string {
        $winner=null;$rank=0;
        foreach($statuses as $status){if(!is_string($status))continue;$score=self::ORDER[$status]??0;if($score>$rank){$winner=$status;$rank=$score;}}
        return $winner;
    }
    public static function status(PDO $db,int $uid,string $kind,string $id):?string {
        if($uid<1||!WorkspaceRepository::validFavoriteId($kind,$id))return null;
        $uuid=$id;
        if($kind==='companies' && ResearchR07::isCompanyId($id))$uuid=ResearchR07::dbId($db,'companies',$id)??'';
        if($kind==='jobs' && ResearchR07::isJobId($id))$uuid=ResearchR07::dbId($db,'jobs',$id)??'';
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$uuid))return null;
        if($kind==='companies'){
            $tracks=ApplicationRepository::companyTracks($db,$uid,$uuid);
            // Choose the strongest job for the company, except the company-only consideration/applied
            // does not outweigh an advanced job. Never write this derived state into DB.
            return self::best(array_column($tracks,'status'));
        }
        $q=$db->prepare('SELECT a.status FROM application_tracks a WHERE a.user_id=? AND a.job_id=? AND a.archived_at IS NULL LIMIT 1');
        $q->execute([$uid,$uuid]);$value=$q->fetchColumn();return is_string($value)?($value):null;
    }
    public static function decorate(PDO $db,string $kind,string $id,string $body):string {
        $me=Identity::current();if($me===null)return $body;
        $uid=(int)$me['id'];$headline='';
        try{
            $headline=ViewHistory::visit($db,$uid,$kind,$id);
            $status=self::status($db,$uid,$kind,$id);
            if($status!==null && isset(ApplicationData::LABELS[$status])){
                $headline.='<div class="application-detail-state '.Security::h(ApplicationData::css($status)).'" role="status"><strong>就活状況：'.Security::h(ApplicationData::LABELS[$status]).'</strong><span>'.Security::h(ApplicationData::message($status)).'</span></div>';
                if($status==='accepted'){
                    $headline.='<div class="celebration-animation" aria-hidden="true"><span class="bloom">✿</span><span class="bloom">🌸</span><span class="bloom">❀</span><span class="spark">✦</span><span class="spark">✺</span><span class="spark">✧</span></div>';
                }
                $body=preg_replace('/<section class="hero compact"/','<section class="hero compact detail-'.Security::h($status).'"',$body,1)??$body;
            }
        }catch(\Throwable $e){error_log('KMM details decoration failed: '.get_class($e));}
        if($headline==='')return $body;
        $needle='<section class="hero compact';$p=strpos($body,$needle);
        if($p===false)return $headline.$body;
        $end=strpos($body,'>',$p);
        return $end===false?$headline.$body:substr_replace($body,$headline,$end+1,0);
    }
}
