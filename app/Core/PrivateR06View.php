<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Private R06 initial-import view: a source snapshot, never a claim of an open vacancy.
 * Identity checks are repeated here even if the caller already checked permissions.
 */
final class PrivateR06View {
    public const VERSION='research-d1.0.4-R06';

    public static function snapshot(PDO $db,string $type,string $id):?array {
        if(Identity::current()===null || !in_array($type,['companies','jobs'],true)
            || preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1)return null;
        $stmt=$db->prepare('SELECT base_json,source_checked_on FROM seed_entity_states WHERE record_type=? AND record_id=? AND seed_version=? LIMIT 1');
        $stmt->execute([$type,$id,self::VERSION]);
        $raw=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($raw)||!is_string($raw['base_json']??null)||strlen($raw['base_json'])>200000)return null;
        try {$data=json_decode($raw['base_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return null;}
        if(!is_array($data)||($data['source_release']??null)!=='d1.0.4 R06'
            || !is_array($data['source_record']??null))return null;
        $data['_checked_on']=is_string($raw['source_checked_on']??null)?$raw['source_checked_on']:null;
        return $data;
    }

    public static function displayYear(mixed $raw):string {
        if(is_int($raw)||is_string($raw)){
            $value=(string)$raw;
            if(preg_match('/\A20[2-9][0-9]\z/D',$value)===1)return $value.'年卒';
        }
        return '未確認';
    }

    public static function scalar(mixed $value,string $missing='未確認'):string {
        if(is_string($value)&&trim($value)!=='')return trim($value);
        if(is_int($value)||is_float($value))return (string)$value;
        return $missing;
    }

    public static function jobListMeta(array $record):string {
        $sourceId=(string)($record['source_job_id']??'');
        $current=ResearchR07::job($sourceId);
        if($current!==null){
            return '<p>雇用形態：'.Security::h(ResearchR07::firstValue($current['employment_type']??null)??'未確認')
                .' ／ 勤務地：'.Security::h(ResearchR07::firstValue($current['worksite']??null)??'未確認')
                .' ／ 原典確認：'.Security::h(ResearchR07::firstValue($current['source_check_date']??null)??'未確認')
                .'（掲載と受付中は異なります）</p>';
        }
        $commute=self::commute($record);
        $total=$commute['total_minutes']??null;
        $rail=$commute['rail_minutes_min']??null;
        $travel=(is_int($total)&&$total>=0&&$total<=10000)?$total.'分（総計・原典記録）'
            :((is_int($rail)&&$rail>=0&&$rail<=10000)?$rail.'分（駅間参考・総計未確認）':'未確認');
        return '<p>雇用形態：'.Security::h(self::scalar($record['employment_type']??null))
            .' ／ 給与：'.Security::h(self::primarySalary($record))
            .' ／ 最寄り駅：'.Security::h(self::scalar($commute['station']??null))
            .' ／ 副都心：'.Security::h(self::scalar($commute['hub']??null))
            .' ／ 所要：'.Security::h($travel)
            .' ／ 勤務地：'.Security::h(self::scalar($record['source_record']['worksite']??null)).'</p>';
    }

    public static function companyDetail(PDO $db,array $company,array $record,bool $locationsOnly):string {
        if(Identity::current()===null)return '';
        $sourceId=(string)($record['source_company_id']??'');
        if(ResearchR07::company($sourceId)!==null && !$locationsOnly){
            return ResearchR07::companyDetails($db,$sourceId,true).'<p><a class="text-link" href="/companies">企業一覧へ戻る →</a></p>';
        }
        $src=ResearchR07::company($sourceId)??$record['source_record'];
        $html='<section class="hero compact"><span class="eyebrow">RESEARCH · 現行データ</span><h1>'
            .Security::h(ResearchR07::company($sourceId)!==null?ResearchR07::displayName(ResearchR07::company($sourceId)):(string)$company['display_name']).'</h1><p>調査資料の記録値を閲覧できます。</p>'.ResearchR07::compareButton('companies',(string)($record['source_company_id']??'')).'</section>';
        $srcId=(string)($record['source_company_id']??'');
        $html.='<section class="section"><h2>企業情報</h2>'.ResearchR07::pairTable([
            '法人・運営主体'=>$src['legal_name']??$company['legal_name']??null,
            'ブランド'=>$src['brand']??null,
            '資料記載地域'=>$src['region_as_recorded_2026_11_19']??null,
            '情報収録日'=>$record['_checked_on']??null,
        ]);
        $summary=ResearchR07::value($src['company_summary']??null);
        if(!$locationsOnly&&$summary!==null&&!str_contains($summary,'既存リンク台帳から引継ぎ'))
            $html.='<p>'.Security::h($summary).'</p>';
        $html.='</section><section class="section"><h2>勤務地候補と所在地</h2>';
        $locs=BusinessRepository::list($db,'company_locations',false,100,0,(string)$company['id']);
        if(!$locs)$html.='<p>未確認。本社所在地と実勤務地は区別します。</p>';
        foreach($locs as $loc){
            $html.='<article class="panel"><h3>'.Security::h(self::scalar($loc['name']??null,'勤務地候補')).'</h3><p>'
                .Security::h(self::scalar($loc['address']??null)).' ／ 状態：'.Security::h(BusinessData::verificationLabel((string)($loc['verification_state']??'unconfirmed'))).'</p></article>';
        }
        $html.='</section>';
        if($locationsOnly)return $html.'<p><a class="text-link" href="/companies/'.Security::h((string)$company['id']).'">企業詳細へ戻る →</a></p>';
        $html.='<section class="section"><h2>求人（募集状況未確認）</h2>';
        $stmt=$db->prepare("SELECT j.id,j.title,j.graduate_year,j.recruitment_state FROM jobs j JOIN seed_entity_states st ON st.record_id=j.id AND st.record_type='jobs' AND st.seed_version=? WHERE j.company_id=? AND j.archived_at IS NULL ORDER BY j.title LIMIT 100");
        $stmt->execute([self::VERSION,$company['id']]);$jobs=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$jobs)$html.='<p>R06登録求人はありません。</p>';
        foreach($jobs as $job){
            $jobR06=self::snapshot($db,'jobs',(string)$job['id']);
            $html.='<article class="panel"><h3><a class="text-link" href="/jobs/'.Security::h((string)$job['id']).'">'.Security::h((string)$job['title']).'</a></h3>'
                .'<p>'.Security::h(self::displayYear($job['graduate_year'])).' ／ 募集状態：未確認</p>';
            if($jobR06!==null)$html.=self::jobListMeta($jobR06);
            $html.='</article>';
        }
        $html.='</section><section class="section"><h2>企業の原典リンク・補充情報</h2>';
        foreach(['company_official_urls'=>'企業公式','recruit_urls'=>'採用情報','company_research_sources'=>'企業調査出典'] as $key=>$label){
            $urls=$src[$key]??[];
            if(!is_array($urls))continue;
            foreach(array_slice($urls,0,30) as $u){
                if(!ResearchExternalLinks::safeUrl($u))continue;
                $html.='<a class="btn ghost" target="_blank" rel="noopener noreferrer nofollow external" href="'.Security::h($u).'">'.Security::h($label).' ↗</a>';
            }
        }
        $html.='<p class="note">公開サイトの現在の状態を再調査したものではありません。</p></section>';
        if(ResearchR07::company($srcId)!==null)$html.=ResearchR07::companyDetails($db,$srcId);
        return $html.'<p><a class="text-link" href="/companies">企業一覧へ戻る →</a></p>';
    }

    public static function jobDetail(array $job,array $record,array $work):string {
        if(Identity::current()===null)return '';
        $sourceId=(string)($record['source_job_id']??'');
        if(ResearchR07::job($sourceId)!==null){
            return ResearchR07::jobDetails(Database::connected(),$sourceId,true)
                .'<p><a class="text-link" href="/companies/'.Security::h((string)$job['company_id']).'">企業詳細へ →</a></p>';
        }
        $src=$record['source_record'];$comm=self::commute($record);
        $archived=ResearchR07Scope::excluded('jobs',$sourceId);
        $html='<section class="hero compact"><span class="eyebrow">RESEARCH · R07 Batch 6</span><h1>'
            .Security::h((string)$job['title']).'</h1><p>情報収集の記録値を表示します。</p>'.ResearchR07::compareButton('jobs',(string)($record['source_job_id']??'')).'</section>';
        $html.=$archived?'<p class="note">この求人は現行の調査対象外となりました。元の記録を履歴として表示しています。応募受付・適格性は未確認です。</p>':'';
        $html.='<section class="section"><h2>求人情報</h2>';
        $pairs=[
            '雇用形態'=>self::scalar($record['employment_type']??null),
            '卒業年度'=>self::displayYear($job['graduate_year']??null),
            '募集状態'=>self::scalar((ResearchR07::job((string)($record['source_job_id']??''))['recheck_r07']['recruitment_status']??null)??($record['source_record']['screening_status']??null),'収録済み情報なし'),
            '給与（主表示）'=>self::primarySalary($record),
            '勤務予定地'=>self::scalar($src['worksite']??$src['worksite_address']??null),
            '情報収録日'=>self::scalar($record['_checked_on']??null),
            '書類選考を含む選考回数'=>self::scalar($record['selection_round_count']??null),
        ];
        $html.=ResearchR07::pairTable($pairs);
        $html.='</section><section class="section"><h2>勤務先とアクセス（原典にある範囲）</h2>';
        if(!$work)$html.='<p>実勤務地は未確認です。本社住所で代用しません。</p>';
        foreach($work as $w)$html.='<p>'.Security::h(self::scalar($w['address']??null)).'（'.Security::h(BusinessData::assignmentLabel((string)($w['assignment_state']??'candidate'))).'）</p>';
        $html.=ResearchR07::pairTable([
            '最寄り駅'=>self::scalar($comm['station']??null),
            '最寄り駅から徒歩'=>self::minutes($comm['walk_minutes']??null),
            '比較先副都心'=>self::scalar($comm['hub']??null),
            '電車の所要時間'=>self::railMinutes($comm),
            '記載された総通勤時間'=>self::minutes($comm['total_minutes']??null),
        ]);
        $html.='<p class="note">電車区間時間とドア・ツー・ドア通勤時間は同一ではありません。未収録値の経路や時間は推定しません。</p></section>';
        $html.='<section class="section"><h2>給与・雇用形態・選考の補充情報</h2>';
        $items=$record['salary_source_values']??[];
        if(is_array($items)&&$items!==[]){
            $html.='<p class="note">主表示は同じ職種・雇用条件・給与期間で比較できる金額からのみ選択します。異なる学歴・契約・試用期間・手当・時給は統合しません。</p>';
            $html.='<ul class="r06-pay-supplements">';
            foreach(array_slice($items,0,40) as $item){
                if(!is_array($item))continue;
                $html.='<li><strong>給与原文（'.Security::h(self::scalar($item['from']??null,'出典未分類')).'）：</strong>'.Security::h(self::scalar($item['verbatim']??null)) ;
                foreach(['role_key'=>'職種・コース','condition_key'=>'適用条件','salary_period'=>'給与の単位・期間'] as $field=>$title){
                    if(is_string($item[$field]??null) && trim($item[$field])!=='')$html.='<span> ／ '.Security::h($title).'：'.Security::h($item[$field]).'</span>';
                }
                $html.='</li>';
            }
            $html.='</ul>';
        }else $html.='<p>給与原文は未収録です。給与は未確認として扱います。</p>';
        foreach(['employment_type_note'=>'雇用区分の注記','employment_type_evidence'=>'雇用形態に関する根拠'] as $key=>$label){
            if(is_string($src[$key]??null)&&trim($src[$key])!=='')$html.='<p>'.Security::h($label).'：'.Security::h($src[$key]).'</p>';
        }
        $html.=PrivateR06SelectionFlow::html($record);
        $html.='</section><section class="section"><h2>研究原典</h2>';
        $url=$src['source_url']??null;
        if(ResearchExternalLinks::safeUrl($url))$html.='<a class="btn ghost" target="_blank" rel="noopener noreferrer nofollow external" href="'.Security::h($url).'">求人原典 ↗</a>';
        $html.='<p>原典の資料版：d1.0.4 R06 ／ 収録時点の情報。最新の募集状態は未確認。</p></section>';
        $sId=(string)($record['source_job_id']??'');
        if(ResearchR07::job($sId)!==null)$html.=ResearchR07::jobDetails(Database::connected(),$sId);
        return $html.'<p><a class="text-link" href="/companies/'.Security::h((string)$job['company_id']).'">企業詳細へ →</a></p>';
    }

    /** A single primary amount; do not merge differing courses, salaries, or pay periods. */
    public static function primarySalary(array $record):string {
        $offers=self::salaryOffers($record);
        if($offers===[])return '未確認（給与原文・補充情報を参照）';
        $first=$offers[0];
        $group=[$first];
        if($first['role']!=='' && $first['condition']!=='' && $first['period']!==''){
            $group=array_values(array_filter($offers,static fn(array $o):bool =>
                $o['role']===$first['role'] && $o['condition']===$first['condition'] && $o['period']===$first['period']));
        }
        $best=$first;
        foreach($group as $offer){
            if($offer['amount']<$best['amount'])$best=$offer;
        }
        $source=count($group)>1 ? '（同一職種・同一条件の最低値。他の値は補充情報）'
            : '（他の値・適用条件は補充情報を参照）';
        return $best['label'].number_format($best['amount']).'円'.$source;
    }
    public static function primaryMonthlyAmount(array $record):?int {
        $offers=self::salaryOffers($record);
        if($offers===[])return null;
        $first=$offers[0];$best=$first['amount'];
        if($first['role']!==''&&$first['condition']!==''&&$first['period']!==''){
            foreach($offers as $o){
                if($o['role']===$first['role']&&$o['condition']===$first['condition']&&$o['period']===$first['period'])$best=min($best,$o['amount']);
            }
        }
        return $best;
    }
    /** Parse only amounts explicitly labelled as monthly; do not infer from hourly trial wages. */
    private static function salaryOffers(array $record):array {
        $items=$record['salary_source_values']??[];
        if(!is_array($items))return [];
        $offers=[];
        foreach(array_slice($items,0,40) as $item){
            if(!is_array($item)||!is_string($item['verbatim']??null))continue;
            $text=$item['verbatim'];$value=null;$label='月給';
            if(preg_match('/月給\s*([0-9]{1,3}(?:[,，][0-9]{3})+|[0-9]{5,7})(?![0-9])/u',$text,$m)===1){
                $value=(int)str_replace([',','，'],'',$m[1]);
            }elseif(preg_match('/月給\s*([0-9]{1,3}(?:\.[0-9]{1,2})?)\s*万円/u',$text,$m)===1){
                $value=(int)round((float)$m[1]*10000);
            }elseif(preg_match('/((?:専門)?[１２12]年(?:制)?卒|(?:専門)?[１２12]年制)[^0-9]{0,18}([0-9]{1,3}(?:[,，][0-9]{3})+|[0-9]{5,7})円/u',$text,$m)===1){
                $value=(int)str_replace([',','，'],'',$m[2]);$label=$m[1].'：月給';
            }
            // An unqualified number, hourly rate, bonus, overtime, or allowance is not a monthly salary.
            if(!is_int($value)||$value<50000||$value>1500000)continue;
            $period=is_string($item['salary_period']??null)?trim($item['salary_period']):'';
            if($period!==''&&!in_array($period,['monthly','月給','月額'],true))continue;
            $offers[]=['amount'=>$value,'label'=>$label,
                'role'=>is_string($item['role_key']??null)?trim($item['role_key']):'',
                'condition'=>is_string($item['condition_key']??null)?trim($item['condition_key']):'',
                'period'=>$period];
        }
        return $offers;
    }

    private static function commute(array $record):array {
        $v=$record['commute_source_record']??($record['source_record']['commute']??[]);
        return is_array($v)?$v:[];
    }
    private static function minutes(mixed $v):string {
        return (is_int($v)&&$v>=0&&$v<=10000)?$v.'分':'未確認';
    }
    private static function railMinutes(array $v):string {
        $lo=$v['rail_minutes_min']??null;$hi=$v['rail_minutes_max']??null;
        if(!is_int($lo)||$lo<0||$lo>10000)return '未確認';
        return (is_int($hi)&&$hi>=$lo&&$hi<=10000&&$lo!==$hi)?$lo.'〜'.$hi.'分':$lo.'分';
    }
}
