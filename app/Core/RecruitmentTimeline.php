<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Month-based cross-company recruitment facts. Never invent a date from the record's check date. */
final class RecruitmentTimeline {
    public static function isRoute(string $path):bool {return $path==='/jobs/timeline';}
    public static function month(mixed $v):string {
        if(is_string($v)&&preg_match('/\A20\d\d-(0[1-9]|1[0-2])\z/D',$v))return $v;
        return (new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y-m');
    }
    public static function dates(array $job):array {
        $dates=[];
        // Exact deadline/start in the job record or a clearly labelled public job condition.
        foreach(['deadline_on'=>'応募締切','application_deadline'=>'応募締切','entry_deadline'=>'エントリー締切','recruitment_start_on'=>'受付開始','application_start_on'=>'受付開始'] as $key=>$label){
            $raw=$job[$key]??null;
            if(!is_string($raw))continue;
            $raw=str_replace('/','-',$raw);
            if(preg_match('/\A(20\d\d)-(\d\d)-(\d\d)\z/D',$raw,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))$dates[$label.'|'.$raw]=[$raw,$label];
        }
        foreach(['employment_type_evidence','recruitment_status'] as $key){
            $text=$job[$key]??null;if(!is_string($text))continue;
            if(preg_match_all('/(?:締切|応募期限|エントリー期限)[：: ]*(20\d\d)[\/年\-](\d{1,2})[\/月\-](\d{1,2})日?/u',$text,$ms,PREG_SET_ORDER)){
                foreach($ms as $m)if(checkdate((int)$m[2],(int)$m[3],(int)$m[1])){$date=sprintf('%04d-%02d-%02d',$m[1],$m[2],$m[3]);$dates['応募締切|'.$date]=[$date,'応募締切'];}
            }
        }
        return array_values($dates);
    }
    public static function events(PDO $db):array {
        $known=[];$unknown=[];
        $recorded=[];
        // Canonical published job data is considered only when a matching deadline actually exists.
        $q=$db->query("SELECT j.id,j.title,j.deadline_on,c.display_name FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.archived_at IS NULL AND c.visibility='published' AND j.deadline_on IS NOT NULL ORDER BY j.deadline_on LIMIT 1000");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$date=(string)$r['deadline_on'];if(!preg_match('/\A20\d\d-\d\d-\d\d\z/',$date))continue;
            $source=ResearchR07::sourceId($db,'jobs',(string)$r['id']);if($source!==null)$recorded[$source]=true;
            $known[$date.'|'.($source??$r['id'])]=['date'=>$date,'label'=>'応募締切（記録値）','job'=>$r['title'],'company'=>$r['display_name'],'url'=>'/jobs/'.$r['id']];
        }
        foreach(ResearchR07::jobs() as $id=>$job){
            $company=ResearchR07::company((string)($job['company_id']??''));
            $title=ResearchR07::jobTitle($job);$companyName=$company!==null?ResearchR07::displayName($company):'企業未確認';
            $dates=self::dates($job);
            if($dates===[]){$unknown[$id]=['title'=>$title,'company'=>$companyName,'url'=>ResearchR07::jobUrl($db,$id)];continue;}
            foreach($dates as [$date,$label]){
                if($label==='応募締切'&&isset($recorded[$id]))continue;
                $known[$date.'|'.$id.'|'.$label]=['date'=>$date,'label'=>$label,'job'=>$title,'company'=>$companyName,'url'=>ResearchR07::jobUrl($db,$id)];
            }
        }
        uasort($known,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['company'],$b['company']));
        return ['known'=>array_values($known),'unknown'=>array_values($unknown)];
    }
    public static function handle(string $method):void {
        if($method!=='GET'){http_response_code(405);header('Allow: GET');Kernel::layout('求人時間軸','<p>閲覧専用です。</p>');return;}
        if(Identity::current()===null){header('Location: /account/login?next=%2Fjobs%2Ftimeline',true,302);return;}
        $db=Database::connected();$month=self::month($_GET['month']??null);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new \DateTimeZone('Asia/Tokyo'));
        if(!$date){http_response_code(422);return;}
        $data=self::events($db);
        $html='<section class="hero compact"><span class="eyebrow">RECRUITMENT TIMELINE</span><h1>求人時間軸</h1><p>企業を横断し、募集要項に明記された日付だけを月別で表示します。公開情報は現在も応募できることを保証しません。</p></section>';
        $html.='<nav class="timeline-month-nav" aria-label="求人年月の切替"><a class="btn ghost" href="/jobs/timeline?month='.$date->modify('-1 month')->format('Y-m').'">前月</a><h2>'.Security::h($date->format('Y年n月')).'</h2><a class="btn ghost" href="/jobs/timeline?month='.$date->modify('+1 month')->format('Y-m').'">翌月</a></nav><section class="section"><h2>この月の募集日程</h2><div class="compact-card-grid">';
        $n=0;foreach($data['known'] as $e){if(substr($e['date'],0,7)!==$month)continue;$n++;
            $html.='<article class="panel compact-data-card"><span class="eyebrow">'.Security::h($e['date']).'・'.Security::h($e['label']).'</span><h3>'.Security::h($e['job']).'</h3><p>'.Security::h($e['company']).'</p><a class="btn ghost" href="'.Security::h($e['url']).'">求人詳細を見る</a></article>';
        }
        if($n===0)$html.='<p class="note">この月の日付が確認できる求人はありません。募集がないと断定するものではありません。</p>';
        $html.='</div></section><section class="section"><h2>日付未確認の求人</h2><p class="note">'.count($data['unknown']).'件。確認日や2027年卒対象といった情報を、応募開始日として推定していません。</p><details><summary>日付未確認の求人を見る</summary><div class="compact-card-grid">';
        foreach(array_slice($data['unknown'],0,count(ResearchR07::jobs())) as $v)$html.='<article class="panel compact-data-card"><h3>'.Security::h($v['title']).'</h3><p>'.Security::h($v['company']).'</p><a href="'.Security::h($v['url']).'">詳細</a></article>';
        $html.='</div></details></section>';
        Kernel::layout('求人時間軸',$html);
    }
}
