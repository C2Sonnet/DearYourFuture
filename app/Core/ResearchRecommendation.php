<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** R07 snapshot scoring: missing facts are never silently treated as passing. */
final class ResearchRecommendation {
    /** One private preference row per user; other saved directory searches stay separate. */
    public const SAVED_LABEL='__kmm_home_recommendation__';
    public const GROUPS=[
        '勤務地・通勤'=>[
            'tokyo'=>'勤務地が東京都内','hub30'=>'記録済みの電車移動が30分以内',
            'worksite'=>'勤務地の記録がある','station'=>'最寄駅が記録されている',
            'kanagawa'=>'勤務地が神奈川県内','chiba'=>'勤務地が千葉県内','saitama'=>'勤務地が埼玉県内',
            'hub40'=>'記録済みの電車移動が40分以内','hub60'=>'記録済みの電車移動が60分以内'],
        '給与・雇用形態'=>[
            'salary20'=>'該当求人の月給が20万円以上','salary25'=>'該当求人の月給が25万円以上',
            'salary30'=>'該当求人の月給が30万円以上','regular'=>'正社員',
            'contract'=>'契約社員','type_unknown'=>'雇用形態がまだ未確認'],
        '応募条件・卒業年度'=>[
            'age'=>'年齢不問','education'=>'学歴不問','graduate2027'=>'2027年卒向け'],
        '仕事内容（求人名による分類）'=>[
            'pastry'=>'製菓・パティシエ','bread'=>'製パン','research'=>'研究','development'=>'商品開発',
            'quality'=>'品質管理・品質保証','other_role'=>'その他の専門職'],
        '選考'=>['selection2'=>'選考2回以内（確認済み）','selection3'=>'選考3回以内（確認済み）'],
        '掲載状況'=>['source_present'=>'求人の出典URLが記録されている（現在募集中とは限りません）'],
    ];
    public const DEFAULTS=['tokyo'=>true,'hub30'=>true,'salary20'=>true,'age'=>true,'education'=>true];
    public static function keys():array {
        $keys=[];foreach(self::GROUPS as $options)foreach($options as $key=>$label)$keys[]=$key;
        return $keys;
    }
    public static function validatedSelection(array $raw):array {
        $allowed=array_fill_keys(self::keys(),true);$selected=[];
        if(count($raw)>count($allowed))throw new \InvalidArgumentException('Too many recommendation filters');
        foreach($raw as $key=>$value){
            if(!is_string($key)||!isset($allowed[$key])||$value!=='1')throw new \InvalidArgumentException('Invalid recommendation filter');
            $selected[$key]=true;
        }
        return $selected;
    }
    public static function conditions(?PDO $db=null,?int $uid=null):array {
        if($db===null||$uid===null)return self::DEFAULTS;
        $q=$db->prepare('SELECT filters_json FROM user_saved_filters WHERE user_id=? AND label=? ORDER BY created_at DESC LIMIT 1');
        $q->execute([$uid,self::SAVED_LABEL]);$json=$q->fetchColumn();
        if(!is_string($json))return self::DEFAULTS;
        try{$decoded=json_decode($json,true,16,JSON_THROW_ON_ERROR);if(!is_array($decoded))return self::DEFAULTS;return self::validatedSelection($decoded);}
        catch(\Throwable){return self::DEFAULTS;}
    }
    public static function persist(PDO $db,int $uid,array $selected,bool $reset):void {
        if($uid<1)throw new \InvalidArgumentException('Invalid user');
        if(!$reset)$selected=self::validatedSelection($selected);
        $db->beginTransaction();
        try{
            $query=$db->prepare('DELETE FROM user_saved_filters WHERE user_id=? AND label=?');
            $query->execute([$uid,self::SAVED_LABEL]);
            if(!$reset){
                $statement=$db->prepare('INSERT INTO user_saved_filters(id,user_id,label,filters_json) VALUES (?,?,?,?)');
                $statement->execute([bin2hex(random_bytes(16)),$uid,self::SAVED_LABEL,json_encode($selected,JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /**
     * Historic R06 records whose exact job posting has explicitly ended, plus
     * the one contradictory posting. Keep the records visible in search/details;
     * never rank them as a recommended current opening.
     */
    private const CLOSED_OR_CONFLICT_IDS = [
        'JOB-0010','JOB-0030','JOB-0055','JOB-0065','JOB-0067',
        'JOB-0071','JOB-0073','JOB-0119','JOB-0123','JOB-0126',
    ];
    public static function recommendationEligible(array $job):bool {
        return !in_array($job['job_id']??null,self::CLOSED_OR_CONFLICT_IDS,true);
    }
    private static function excluded(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT DISTINCT company_id FROM application_tracks WHERE user_id=? AND archived_at IS NULL UNION SELECT company_id FROM user_favorite_companies WHERE user_id=?');
        $q->execute([$uid,$uid]);return array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
    }
    /** Only recorded job workplaces count as Tokyo matches; a head office is not a job site. */
    private static function tokyoWorksite(array $job):bool {
        $b6=$job['r07_batch6_research']??[];
        $re=$job['recheck_r07']??[];
        $source=is_array($b6)?($b6['worksite_address_public']??null):null;
        $source??=is_array($re)?($re['worksite_verified_address']??null):null;
        $source??=$job['worksite']??null;
        $value=ResearchR07::firstValue($source);
        return $value!==null && preg_match('/(?:東京都|東京[・･])/u',$value)===1;
    }
    private static function regionWorksite(array $job,string $region):bool {
        $b6=$job['r07_batch6_research']??[];$re=$job['recheck_r07']??[];
        $source=(is_array($b6)?($b6['worksite_address_public']??null):null)
            ??(is_array($re)?($re['worksite_verified_address']??null):null)
            ??($job['worksite']??null);
        $v=ResearchR07::firstValue($source);
        return $v!==null&&str_contains($v,$region);
    }
    /** Do not treat a salary from another recruitment course as this job's salary. */
    private static function monthlySalary(PDO $db,array $job):?int {
        $b6=$job['r07_batch6_research']??[];
        if(is_array($b6)&&($b6['full_job_course_publicly_readable']??false)===true){
            $amount=$b6['salary_jpy_specialist']??null;
            if(is_int($amount)&&$amount>0&&$amount<10000000)return $amount;
        }
        $re=$job['recheck_r07']??[];
        $components=is_array($re)?($re['compensation_components']??[]):[];
        if(is_array($components)){
            $amount=$components['total']??null;
            if(is_int($amount)&&$amount>0&&$amount<10000000)return $amount;
        }
        $dbJob=ResearchR07::dbId($db,'jobs',(string)($job['job_id']??''));
        if($dbJob!==null){
            $r06=PrivateR06View::snapshot($db,'jobs',$dbJob);
            if($r06!==null)return PrivateR06View::primaryMonthlyAmount($r06);
        }
        return null;
    }
    /** Shared facts for recommendations and the user-selected directory filters. */
    private static function facts(PDO $db,array $job):array {
        $rail=ResearchR07::rail($job);
        $comm=$job['commute']??[];
        $hub=is_array($comm)?ResearchR07::firstValue($comm['hub']??null):null;
        $amount=self::monthlySalary($db,$job);
        $re=$job['recheck_r07']??[];if(!is_array($re))$re=[];
        return [
            'tokyo'=>self::tokyoWorksite($job),
            'hub30'=>$rail!==null&&$hub!==null&&!in_array($hub,['不明','未確認','未収録','null'],true)&&$rail['minutes']<=30,
            'salary20'=>$amount!==null&&$amount>=200000,
            'salary25'=>$amount!==null&&$amount>=250000,
            'salary30'=>$amount!==null&&$amount>=300000,
            'age'=>ResearchR07::firstValue($re['age_requirements']??null)==='年齢不問',
            'education'=>ResearchR07::firstValue($re['education_requirements']??null)==='学歴不問',
            'regular'=>ResearchR07::firstValue($job['employment_type']??null)==='正社員',
            'contract'=>ResearchR07::firstValue($job['employment_type']??null)==='契約社員',
            'type_unknown'=>ResearchR07::firstValue($job['employment_type']??null)==='種類不明',
            'graduate2027'=>(string)($job['recruitment_year']??'')==='2027',
            'worksite'=>ResearchR07::firstValue($job['worksite']??null)!==null,
            'station'=>ResearchR07::firstStation($job)!==null,
            'kanagawa'=>self::regionWorksite($job,'神奈川'),
            'chiba'=>self::regionWorksite($job,'千葉'),
            'saitama'=>self::regionWorksite($job,'埼玉'),
            'hub40'=>$rail!==null&&$hub!==null&&$rail['minutes']<=40,
            'hub60'=>$rail!==null&&$hub!==null&&$rail['minutes']<=60,
            'pastry'=>preg_match('/製菓|パティシエ|ケーキ|菓子/u',ResearchR07::jobTitle($job))===1,
            'bread'=>preg_match('/パン|ベーカリー|製パン/u',ResearchR07::jobTitle($job))===1,
            'research'=>preg_match('/研究|分析/u',ResearchR07::jobTitle($job))===1,
            'quality'=>preg_match('/品質管理|品質保証/u',ResearchR07::jobTitle($job))===1,
            'other_role'=>preg_match('/その他|総合職/u',ResearchR07::jobTitle($job))===1,
            'selection2'=>is_numeric($job['selection_round_count']??null)&&(int)$job['selection_round_count']>=1&&(int)$job['selection_round_count']<=2,
            'selection3'=>is_numeric($job['selection_round_count']??null)&&(int)$job['selection_round_count']>=1&&(int)$job['selection_round_count']<=3,
            'development'=>preg_match('/商品開発|製品開発|メニュー開発/u',ResearchR07::jobTitle($job))===1,
            'source_present'=>ResearchExternalLinks::safeUrl($job['source_url']??null),
        ];
    }
    public static function results(PDO $db,int $uid,array $criteria,int $limit=8):array {
        $excluded=self::excluded($db,$uid);$jobs=[];$order=0;
        foreach(ResearchR07::jobs() as $id=>$job){
            if(!self::recommendationEligible($job))continue;
            $co=ResearchR07::company((string)($job['company_id']??''));if($co===null)continue;
            $dbId=ResearchR07::dbId($db,'companies',(string)$job['company_id']);
            if($dbId!==null&&isset($excluded[$dbId]))continue;
            $facts=self::facts($db,$job);
            $score=0;$match=[];foreach($criteria as $k=>$on)if($on&&($facts[$k]??false)){$score++;$match[]=$k;}
            $jobs[]=compact('id','job','co','score','match','order');$order++;
        }
        usort($jobs,static fn(array $a,array $b)=>($b['score']<=>$a['score'])?:($a['order']<=>$b['order']));
        $best=[];
        foreach($jobs as $j){
            $cid=(string)$j['job']['company_id'];
            // CO-0050 is an explicitly documented alias of the same employer CO-0098.
            // Keep each source job intact but avoid recommending the employer twice.
            $canonicalCompany=$cid==='CO-0050'?'CO-0098':$cid;
            if(!isset($best[$canonicalCompany])){
                $j['co']=ResearchR07::company($canonicalCompany)??$j['co'];
                $best[$canonicalCompany]=$j;
            }
        }
        return ['jobs'=>array_slice($jobs,0,$limit),'companies'=>array_slice(array_values($best),0,$limit)];
    }
    public static function requestedFilters(array $raw):array {
        $out=[];foreach(['r07_tokyo','r07_hub30','r07_salary20','r07_age_any','r07_school_any'] as $key){if(($raw[$key]??'')==='1')$out[]=$key;}
        return $out;
    }
    public static function matches(PDO $db,string $type,array $source,array $raw):bool {
        $requested=self::requestedFilters($raw);if(!$requested)return true;
        $jobs=$type==='companies'?ResearchR07::companyJobs((string)($source['company_id']??'')):[$source];
        foreach($jobs as $job){
            // Search still shows historical / closed jobs if they match a user filter;
            // only the recommendation ranking excludes explicitly closed postings.
            $facts=self::facts($db,$job);
            $checks=[
                'r07_tokyo'=>$facts['tokyo'],
                'r07_hub30'=>$facts['hub30'],
                'r07_salary20'=>$facts['salary20'],
                'r07_age_any'=>$facts['age'],
                'r07_school_any'=>$facts['education'],
            ];
            $ok=true;foreach($requested as $key){if(!($checks[$key]??false)){$ok=false;break;}}
            if($ok)return true;
        }
        return false;
    }
    public static function counts():array {
        $companies=ResearchR07::companies();$jobs=ResearchR07::jobs();$sources=[];$employment=[];$region=[];
        foreach($companies as $co){
            $area=ResearchR07::prefecture($co);if($area!==null)$region[$area]=($region[$area]??0)+1;
            foreach(ResearchR07::links($co) as $url=>$type)$sources[$url]=true;
        }
        foreach($jobs as $job){
            $form=ResearchR07::firstValue($job['employment_type']??null);if($form!==null)$employment[$form]=($employment[$form]??0)+1;
            foreach(ResearchR07::links($job) as $url=>$type)$sources[$url]=true;
        }
        arsort($employment);arsort($region);
        return ['company_count'=>count($companies),'job_count'=>count($jobs),'source_count'=>count($sources),'employment'=>$employment,'prefectures'=>$region];
    }
    public static function render(PDO $db,int $uid):string {
        $criteria=self::conditions($db,$uid);$counts=self::counts();$result=self::results($db,$uid,$criteria);
        $html='<section class="section r07-stat-section"><h2>収録データ集計</h2><div class="r07-stat-cards"><article class="panel"><strong>'.(int)$counts['company_count'].'</strong><span>企業数</span></article><article class="panel"><strong>'.(int)$counts['job_count'].'</strong><span>求人数</span></article><article class="panel"><strong>'.(int)$counts['source_count'].'</strong><span>参考資料リンク数（重複除去）</span></article></div>';
        $html.='<div class="r07-chart-grid">';
        foreach(['employment'=>'求人形態','prefectures'=>'企業場所（都道府県別）'] as $type=>$label){
            $data=[];foreach($counts[$type] as $name=>$value)$data[]=['name'=>(string)$name,'value'=>$value];
            $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
            $html.='<article class="r07-chart-panel panel"><h3>'.Security::h($label).'</h3><div class="r07-chart" data-r07-chart="'.Security::h($type).'" aria-label="'.Security::h($label).'の内訳グラフ" data-chart-values="'.Security::h($json).'"></div></article>';
        }
        $html.='</div></section>';
        $labels=[];foreach(self::GROUPS as $group=>$values)foreach($values as $key=>$value)$labels[$key]=$value;
        $current=[];foreach($criteria as $key=>$enabled)if($enabled&&isset($labels[$key]))$current[]=$labels[$key];
        $html.='<section class="section" id="recommend-conditions"><h2>おすすめ条件</h2><p>条件に一致する数が多い求人・企業から表示します。確認されていない項目は一致として数えません。既に応募・お気に入り登録している企業と募集終了が判明した求人は除外します。</p>';
        $html.='<div class="recommend-overview"><p><strong>現在の条件：</strong>'.($current!==[]?Security::h(implode(' ／ ',$current)):'指定なし').'（'.count($current).'件）</p><button type="button" class="btn primary" data-recommend-open uk-toggle="target: #recommend-conditions-modal" aria-haspopup="dialog" aria-controls="recommend-conditions-modal">おすすめ条件を変更</button></div></section>';
        $html.='<div id="recommend-conditions-modal" class="uk-modal recommend-uk-modal" uk-modal hidden aria-hidden="true"><div class="uk-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="recommend-title" tabindex="-1"><button class="uk-modal-close-default" type="button" aria-label="閉じる" data-recommend-close>×</button><h2 id="recommend-title">おすすめ条件を変更</h2><p class="note">チェックは複数選択できます。選んだ条件を個人用のおすすめ設定として保存します。「初期条件に戻す」で標準の5条件を復元できます。</p>';
        $html.='<form method="post" action="/" id="recommend-settings-form">'.Session::csrfField();
        foreach(self::GROUPS as $group=>$options){
            $html.='<fieldset class="recommend-option-group"><legend>'.Security::h($group).'</legend>';
            foreach($options as $key=>$label)$html.='<label><input type="checkbox" name="criteria['.Security::h($key).']" value="1"'.(isset($criteria[$key])&&$criteria[$key]?' checked':'').'> '.Security::h($label).'</label>';
            $html.='</fieldset>';
        }
        $html.='<div class="recommend-modal-actions"><button class="btn primary" type="submit" name="recommend_action" value="save">変更を保存</button><button class="btn ghost" type="submit" name="recommend_action" value="reset" formnovalidate>初期条件に戻す</button><button class="btn ghost" type="button" data-recommend-close>閉じる</button></div></form></div></div><script src="/assets/recommend-modal.js" defer></script>';
        foreach(['jobs'=>'求人おすすめ','companies'=>'企業おすすめ'] as $kind=>$label){
            $html.='<section class="section"><h2>'.$label.'</h2><div class="cards">';
            foreach($result[$kind] as $item){
                $id=$kind==='companies'?(string)$item['co']['company_id']:(string)$item['id'];
                $name=$kind==='companies'?ResearchR07::displayName($item['co']):ResearchR07::jobTitle($item['job']);
                $url=$kind==='companies'?ResearchR07::companyUrl($db,$id):ResearchR07::jobUrl($db,$id);
                $html.='<article class="panel"><h3>'.Security::h($name).'</h3><p>合致条件：'.(int)$item['score'].'件</p>';
                $detail=$kind==='companies'?ResearchR07::quickFacts($item['co']):ResearchR07::firstValue($item['job']['employment_type']??null);
                if($detail!==null&&$detail!=='')$html.='<p>'.Security::h($detail).'</p>';
                $html.='<a class="btn ghost" href="'.Security::h($url).'">詳細を見る</a>'.ResearchR07::compareButton($kind,$id).'</article>';
            }
            $html.='</div></section>';
        }
        return $html;
    }
}
