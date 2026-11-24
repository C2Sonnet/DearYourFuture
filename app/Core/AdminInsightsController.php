<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Protected analytical aggregates, no user's private application data. */
final class AdminInsightsController {
    public static function isRoute(string $path):bool{return $path==='/admin/data/statistics';}
    public static function lineData(PDO $db):array {
        $points=[];$start=(new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->modify('-29 days');
        for($i=0;$i<30;$i++){$day=$start->modify('+'.$i.' days')->format('Y-m-d');$points[$day]=['date'=>$day,'company_new'=>0,'company_edited'=>0,'company_deleted'=>0,'job_new'=>0,'job_edited'=>0,'job_deleted'=>0];}
        $stmt=$db->query("SELECT DATE(created_at) AS day,record_type,operation,COUNT(*) AS n FROM business_change_events WHERE record_type IN ('companies','jobs') AND created_at >= DATE_SUB(UTC_DATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at),record_type,operation");
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){$day=(string)$r['day'];if(!isset($points[$day]))continue;$op=strtolower((string)$r['operation']);
            $type=in_array($op,['create','add','insert','new','seed_draft_added','seed_add'],true)?'new':(in_array($op,['archive','delete','remove','deleted'],true)?'deleted':'edited');
            $record=(string)$r['record_type']==='companies'?'company_':'job_';$points[$day][$record.$type]+=(int)$r['n'];
        }
        return array_values($points);
    }
    private static function pieData(array $items):array {arsort($items);$out=[];foreach($items as $label=>$count)if($count>0)$out[]=['name'=>$label,'value'=>$count];return $out;}
    public static function handle(PDO $db,string $path,string $method):void {
        if($method!=='GET'){header('Allow: GET');http_response_code(405);return;}
        $co=ResearchR07::companies();$jobs=ResearchR07::jobs();
        $pref=[];$types=[];$salary=[];$pub=[];
        foreach($co as $company){$key=ResearchR07::prefecture($company)??'所在地未確認';$pref[$key]=($pref[$key]??0)+1;}
        foreach($jobs as $job){$type=ResearchR07::firstValue($job['employment_type']??null)??'雇用形態未確認';$types[$type]=($types[$type]??0)+1;
            $status=ResearchR07::firstValue($job['publication_status']??null)??'未確認';$status=match(true){str_contains($status,'public'),str_contains($status,'published')=>'掲載記録あり（募集受付は未確認）',default=>'掲載・受付の確認が必要'};$pub[$status]=($pub[$status]??0)+1;
            $components=$job['recheck_r07']['compensation_components']??null;
            $value=is_array($components)?($components['total']??null):null;
            $label='初任給未確認';
            if(is_numeric($value) && $value>0 && $value<10000000){
                $amount=(int)$value;
                $label=$amount<200000?'20万円未満':($amount<230000?'20～23万円未満':($amount<260000?'23～26万円未満':'26万円以上'));
            }
            $salary[$label]=($salary[$label]??0)+1;
        }
        $charts=['salary'=>['初任給の内訳（確認できた構造化データのみ）',self::pieData($salary)],'employment'=>['雇用形態',self::pieData($types)],'region'=>['企業の所在地（都道府県別）',self::pieData($pref)],'publication'=>['求人掲載情報の確認区分',self::pieData($pub)]];
        
        $summary=ResearchClassification::data()['summary'];
        foreach(['role_classification'=>'専門技術との適合性分類（求人群）','application_classification'=>'応募確認候補の分類（求人群）','company_best_classification'=>'応募確認候補の分類（企業群）'] as $key=>$title){$items=[];foreach($summary[$key] as $label=>$n)$items[(ResearchClassification::ROLE+ResearchClassification::CANDIDATE)[$label]??'未確定']=$n;$charts[$key]=[$title,self::pieData($items)];}
        $userCount=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $html='<section class="hero compact"><span class="eyebrow">ADMIN / ANALYTICS</span><h1>データ集計</h1><p>管理者向け集計。サイト採用R07データ集（件数は下の集計を参照）と、DBの更新監査記録を分けて表示します。</p></section>';
        $html.='<section class="section"><h2>収録データ集計</h2><p>重複整理後：112企業群・145求人群（原ID保全）</p><div class="data-stats-cards"><article class="panel"><strong>'.$userCount.'</strong><span>登録ユーザー数（開発者を含む）</span></article><article class="panel"><strong>'.count($co).'</strong><span>収録企業</span></article><article class="panel"><strong>'.count($jobs).'</strong><span>収録求人</span></article><article class="panel"><strong>'.count($charts).'</strong><span>分類別集計</span></article></div></section>';
        $json=json_encode(self::lineData($db),JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_THROW_ON_ERROR);
        $html.='<section class="section"><h2>企業・求人の変更件数（日別、直近30日）</h2><p class="note">新規・修正・削除はDBの変更監査から集計します。R07研究原典の差分履歴や外部サイト更新は含みません。データがない日は0件です。</p><div class="panel stats-chart" data-admin-line="'.Security::h($json).'" aria-label="企業と求人別の日別新規・修正・削除件数"></div></section>';
        $html.='<section class="section"><h2>構成比・分布</h2><div class="admin-pie-grid">';
        foreach($charts as $key=>$chart){$json=json_encode($chart[1],JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_THROW_ON_ERROR);$html.='<article class="panel"><h3>'.Security::h($chart[0]).'</h3><div data-admin-pie="'.Security::h($json).'" class="stats-chart" role="group" aria-label="'.Security::h($chart[0]).'"></div></article>';}
        $html.='</div><p class="note">円グラフは各区画に最大4度の隙間を設けています。初任給は同一採用コースで数字が確認できたデータだけを計上し、未確認を分けて表示します。</p></section>';
        Kernel::layout('データ集計',$html);
    }
}
