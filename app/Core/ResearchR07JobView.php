<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Reader-friendly projection of *unchanged* R07 research artifacts.
 * This presentation layer filters research bookkeeping; it never changes evidence,
 * treats a search listing as an open vacancy, or combines salaries across courses.
 */
final class ResearchR07JobView {
    private const MAP = [
        'job_title'=>['職種・求人名','仕事内容・雇用'],
        'employment_type'=>['雇用形態','仕事内容・雇用'],
        'employment_type_in_master'=>['雇用形態','仕事内容・雇用'],
        'employment_type_note'=>['雇用形態の補足','仕事内容・雇用'],
        'employment_type_evidence'=>['雇用形態の根拠','仕事内容・雇用'],
        'recruitment_year'=>['対象卒業年度','応募条件・募集状況'],
        'job_tasks'=>['仕事内容','仕事内容・雇用'],
        'technical_duties'=>['仕事内容','仕事内容・雇用'],
        'technical_role'=>['仕事内容','仕事内容・雇用'],
        'technical_duties_and_sales_risk'=>['仕事内容・販売兼務','仕事内容・雇用'],
        'technical_work_and_sales_risk'=>['仕事内容・販売兼務','仕事内容・雇用'],
        'role'=>['担当職種','仕事内容・雇用'],
        'degree'=>['卒業・学歴条件','応募条件・募集状況'],
        'education'=>['卒業・学歴条件','応募条件・募集状況'],
        'graduation_school_criteria'=>['卒業・学歴条件','応募条件・募集状況'],
        'graduation_and_school_criteria'=>['卒業・学歴条件','応募条件・募集状況'],
        'school_criteria'=>['卒業・学歴条件','応募条件・募集状況'],
        'school_requirements'=>['卒業・学歴条件','応募条件・募集状況'],
        'school_and_applicant_conditions'=>['卒業・学歴条件','応募条件・募集状況'],
        'degree_eligibility'=>['卒業・学歴条件','応募条件・募集状況'],
        'school_2027_conditions'=>['卒業・学歴条件','応募条件・募集状況'],
        'foreign_nationality_and_visa_conditions'=>['外国籍・在留資格の条件','応募条件・募集状況'],
        'foreign_visa'=>['外国籍・在留資格の条件','応募条件・募集状況'],
        'visa_eligibility'=>['外国籍・在留資格の条件','応募条件・募集状況'],
        'worksite'=>['勤務地','勤務地・通勤'],
        'worksite_address'=>['勤務地','勤務地・通勤'],
        'worksite_verified_address'=>['勤務地','勤務地・通勤'],
        'worksite_info'=>['勤務地','勤務地・通勤'],
        'worksite_r07_verified'=>['勤務地','勤務地・通勤'],
        'initial_worksite'=>['最初の配属先','勤務地・通勤'],
        'initial_workplace_evidence'=>['最初の配属先','勤務地・通勤'],
        'initial_worksite_public_evidence'=>['最初の配属先','勤務地・通勤'],
        'worksite_and_initial_assignment'=>['最初の配属先','勤務地・通勤'],
        'nearest_station'=>['最寄駅','勤務地・通勤'],
        'nearest_station_if_published'=>['最寄駅','勤務地・通勤'],
        'station'=>['最寄駅','勤務地・通勤'],
        'walk_minutes'=>['駅から徒歩','勤務地・通勤'],
        'walk_minutes_if_published'=>['駅から徒歩','勤務地・通勤'],
        'hub'=>['主要な乗換拠点','勤務地・通勤'],
        'rail_line'=>['路線','勤務地・通勤'],
        'rail_minutes_min'=>['電車の所要時間（最短）','勤務地・通勤'],
        'rail_minutes_max'=>['電車の所要時間（最長）','勤務地・通勤'],
        'access_notes'=>['通勤・勤務地の注意点','勤務地・通勤'],
        'salary'=>['給与・初任給','給与・休日・待遇'],
        'salary_benefits'=>['給与・初任給','給与・休日・待遇'],
        'salary_and_benefits'=>['給与・初任給','給与・休日・待遇'],
        'salary_benefits_2027_job_if_verified'=>['2027年卒の給与・福利厚生','給与・休日・待遇'],
        'salary_and_conditions'=>['給与・初任給','給与・休日・待遇'],
        'compensation'=>['給与・初任給','給与・休日・待遇'],
        'pay_and_hours'=>['給与・勤務時間','給与・休日・待遇'],
        'salary_components'=>['給与内訳','給与・休日・待遇'],
        'public_recruitment_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'recruitment_public_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'current_application_vacancy_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'public_recruitment_and_current_acceptance'=>['募集・応募受付状況','応募条件・募集状況'],
        'r07_public_status_inherited'=>['募集・応募受付状況','応募条件・募集状況'],
        'public_application_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'job_public_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'status_observed'=>['募集・応募受付状況','応募条件・募集状況'],
        'application_status_verified_2026_11_21'=>['募集・応募受付状況','応募条件・募集状況'],
        'recruitment_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'public_listing_status'=>['募集・応募受付状況','応募条件・募集状況'],
        'batch22_conditions_note'=>['応募条件・試用期間の注意点','応募条件・募集状況'],
        'batch21_refinement_note'=>['応募条件の補足','応募条件・募集状況'],
        'unresolved_blockers'=>['応募前に確認すること','応募条件・募集状況'],
        'unresolved_risks'=>['応募前に確認すること','応募条件・募集状況'],
        'remaining_risks'=>['応募前に確認すること','応募条件・募集状況'],
        'unresolved'=>['応募前に確認すること','応募条件・募集状況'],
        'caveats'=>['応募前に確認すること','応募条件・募集状況'],
        'risk'=>['応募前に確認すること','応募条件・募集状況'],
        'batch18_note'=>['応募前に確認すること','応募条件・募集状況'],
        'batch19_note'=>['応募前に確認すること','応募条件・募集状況'],
        'batch20_note'=>['応募前に確認すること','応募条件・募集状況'],
        'actual_initial_employment'=>['初日からの雇用形態','仕事内容・雇用'],
        'technical_role_characterization'=>['製菓・技術職の担当範囲','仕事内容・雇用'],
        'technical_skill_match'=>['専門技術との対応','仕事内容・雇用'],
        'initial_duties'=>['入社時の仕事内容','仕事内容・雇用'],
        'duties'=>['仕事内容','仕事内容・雇用'],
        'metro_initial_worksite'=>['初期配属候補','勤務地・通勤'],
        'street_address'=>['実勤務地の住所','勤務地・通勤'],
        'shift_start_as_known'=>['勤務開始時刻（出典記録）','勤務地・通勤'],
        'last_mile_walk_minutes_low'=>['駅からの徒歩参考（最短）','勤務地・通勤'],
        'worksite_evidence'=>['勤務地の根拠・注意事項','勤務地・通勤'],
        'shifts'=>['勤務シフト','給与・休日・待遇'],
        'holidays'=>['休日','給与・休日・待遇'],
        'monthly_salary_yen'=>['月給（掲載条件ごとの参考値）','給与・休日・待遇'],
        'trial'=>['試用期間の条件','仕事内容・雇用'],
        'contract'=>['契約・登用の条件','仕事内容・雇用'],
        'evidence_summary'=>['公開原典の確認結果','応募条件・募集状況'],
        'public_finding'=>['公開原典の確認結果','応募条件・募集状況'],
        'unresolved_dependency'=>['応募前に確認すること','応募条件・募集状況'],
        'warning'=>['応募前に確認すること','応募条件・募集状況'],
        'source_caveat'=>['公開根拠の注意','応募条件・募集状況'],
        'precision_note'=>['出典精度の注意','応募条件・募集状況'],
        'foreign_and_visa'=>['外国籍・在留資格の条件','応募条件・募集状況'],
        'graduation'=>['卒業年度・条件','応募条件・募集状況'],
        'selection_round_count'=>['選考回数','選考・応募'],
        'interview_process'=>['選考方法','選考・応募'],
        'selection_process'=>['選考方法','選考・応募'],
    ];
    private const GROUP_ORDER=['仕事内容・雇用','給与・休日・待遇','勤務地・通勤','応募条件・募集状況','選考・応募'];
    private const STATUSES=[
        'published'=>'求人情報が公開されています（現在の募集受付は未確認）',
        'not_yet_verified_for_individual'=>'本人の応募資格・選考状況はまだ確認できていません',
        'not_verified'=>'確認できていません',
        'unreviewed'=>'調査中です',
        '2027_public_listing_only_acceptance_not_confirmed'=>'2027年卒向け求人の掲載を確認していますが、現在の応募受付は未確認です',
        '2027_job_publicly_displayed_current_acceptance_unverified'=>'2027年卒向け求人が公開されていますが、現在の受付状況は未確認です',
        'job_record_present_but_no_current_eligible_2027_confirmation'=>'求人記録はありますが、2027年卒として応募できるかは未確認です',
        'legacy_preexisting_mapping_unverified'=>'求人と正式な雇用主の対応関係は未確認です',
        'search_index_snippet_only'=>'検索結果の抜粋のみ確認済みです',
        'legacy_record_2026_11_19'=>'2026年11月19日の記録です。現在の募集状況は未確認です',
        '2027_public_listing_only'=>'2027年卒向けの求人掲載を確認しました。受付状況は未確認です',
    ];
    private static function date(array $row):string {
        foreach(['checked_on','verified_on','source_check_date','observed_on','published_at','date'] as $key){
            $v=$row[$key]??null;if(is_string($v)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$v))return $v;
        }
        return '2026-11-23'; // dataset snapshot date, not proof of an individual recheck
    }
    private static function batch(array $row):int {
        // Specific field provenance takes precedence over the current snapshot's wrapper label.
        $path=(string)($row['path']??$row['field']??'');
        if(preg_match('/(?:r07_)?batch_?(\d+)/i',$path,$m))return max(1,min(99,(int)$m[1]));
        $src=(string)($row['source']??'');
        if(preg_match('/Batch\s*(\d+)/i',$src,$m))return max(1,min(99,(int)$m[1]));
        return 0;
    }
    private static function normalize(string $value,string $label):?string {
        $v=trim($value);
        $v=preg_replace('/（原典値：[[:space:]]*[^）]*）/u','',$v)??$v;
        if(preg_match('/\A(?:true|false)(?:（原典値[^）]*）)?\z/i',$v))return null; // internal research flags, not user facts
        if($v===''||in_array(mb_strtolower($v),['null','n/a','unknown','none','unreviewed'],true))return null;
        if(isset(self::STATUSES[$v]))$v=self::STATUSES[$v];
        if(preg_match('/\A[a-z][a-z0-9_]*(?:_[a-z0-9_]+)+\z/i',$v))return null; // opaque technical tokens
        if($label==='駅から徒歩'&&preg_match('/\A\d+\z/',$v))$v.='分';
        if(str_starts_with($label,'電車の所要時間')&&preg_match('/\A\d+\z/',$v))$v.='分';
        if($label==='選考回数'&&preg_match('/\A\d+\z/',$v))$v.='回';
        return trim($v)!==''?trim($v):null;
    }
    private static function key(string $raw):string {return strtolower(preg_replace('/[\s\p{P}\p{S}]+/u','',mb_convert_kana(trim($raw),'as','UTF-8'))??trim($raw));}
    /** De-duplicate same field and same value; choose the latest record for duplicates. */
    private static function add(array &$table,string $label,string $group,mixed $raw,string $date,int $priority,int $batch=0):void {
        if(!is_string($raw)&&!is_numeric($raw))return;
        $value=self::normalize((string)$raw,$label);if($value===null)return;
        $identity=self::key($value);
        if(!isset($table[$group][$label][$identity]) || [$date,$batch,$priority] > [$table[$group][$label][$identity]['date'],$table[$group][$label][$identity]['batch'],$table[$group][$label][$identity]['priority']]){
            $table[$group][$label][$identity]=['value'=>$value,'date'=>$date,'batch'=>$batch,'priority'=>$priority];
        }
    }
    public static function entries(string $sourceId,array $job):array {
        $groups=[];
        // Core facts are first-class. Add them into the same field as supplementary facts.
        foreach([
            ['職種・求人名','仕事内容・雇用',$job['job_title']??null],
            ['雇用形態','仕事内容・雇用',$job['employment_type']??null],
            ['対象卒業年度','応募条件・募集状況',$job['recruitment_year']??null],
            ['勤務地','勤務地・通勤',$job['worksite']??null],
            ['最寄駅','勤務地・通勤',ResearchR07::firstStation($job)],
        ] as [$label,$group,$value])self::add($groups,$label,$group,$value,'2026-11-23',4);
        foreach(ResearchR07::jobStructuredFacts($job) as $label=>$value){
            $category=preg_match('/給|月給|手当|休|残業|待遇|シフト/u',$label)?'給与・休日・待遇':
                (preg_match('/勤務地|勤務先|最寄駅|駅から|転勤|配属/u',$label)?'勤務地・通勤':'応募条件・募集状況');
            self::add($groups,$label,$category,$value,'2026-11-23',4);
        }
        foreach(ResearchR07FieldIndex::rows('jobs',$sourceId) as $row){
            if(!is_array($row))continue;
            $path=(string)($row['path']??'');$field=(string)preg_replace('/^.*\./','',$path);
            $map=self::MAP[$field]??null;if($map===null)continue;
            self::add($groups,$map[0],$map[1],$row['value']??null,self::date($row),3,self::batch($row));
        }
        $out=[];
        foreach(self::GROUP_ORDER as $group){
            if(!isset($groups[$group]))continue;
            foreach($groups[$group] as $label=>$values){
                $values=array_values($values);
                usort($values,static fn($a,$b)=>strcmp($b['date'],$a['date'])?:($b['batch']<=>$a['batch'])?:($b['priority']<=>$a['priority'])?:strcmp($a['value'],$b['value']));
                // Avoid a huge screen full of near-identical historical notes.
                $out[$group][$label]=$values;
            }
        }
        // Research sources may repeat exactly the same sentence under multiple headings.
        // Keep the clearest applicant-facing heading only; distinct facts remain visible.
        $preferred=['職種・求人名'=>200,'雇用形態'=>200,'対象卒業年度'=>200,'勤務地'=>200,
            '最寄駅'=>200,'仕事内容'=>180,'給与・初任給'=>180,'給与内訳'=>170,
            '仕事内容・販売兼務'=>165,'最初の配属先'=>165,'応募条件・試用期間の注意点'=>160,
            '募集・応募受付状況'=>165,'2027年卒の給与・福利厚生'=>155,
            '給与原文（R06・コース別条件に注意）'=>70];
        $winners=[];
        foreach($out as $group=>$fields)foreach($fields as $label=>$values)foreach($values as $i=>$row){
            $distinctScope=str_contains($label,'別公式コース')||str_contains($label,'別の一般募集要項')?'別コース:'.$label:'通常';
            $fingerprint=$distinctScope.'|'.self::key($row['value']);
            $candidate=['group'=>$group,'label'=>$label,'index'=>$i,'priority'=>$preferred[$label]??100,
                'date'=>$row['date'],'batch'=>$row['batch'],'source_priority'=>$row['priority']];
            $before=$winners[$fingerprint]??null;
            if($before===null||[$candidate['priority'],$candidate['date'],$candidate['batch'],$candidate['source_priority']]
                >[$before['priority'],$before['date'],$before['batch'],$before['source_priority']])$winners[$fingerprint]=$candidate;
        }
        foreach($out as $group=>$fields)foreach($fields as $label=>$values){
            foreach($values as $i=>$row){
                $scope=str_contains($label,'別公式コース')||str_contains($label,'別の一般募集要項')?'別コース:'.$label:'通常';
                $winner=$winners[$scope.'|'.self::key($row['value'])];
                if($winner['group']!==$group||$winner['label']!==$label||$winner['index']!==$i)unset($out[$group][$label][$i]);
            }
            $out[$group][$label]=array_values($out[$group][$label]);
            if($out[$group][$label]===[])unset($out[$group][$label]);
        }
        foreach($out as $group=>$fields)if($fields===[])unset($out[$group]);
        return $out;
    }
    public static function render(string $sourceId,array $job):string {
        $data=self::entries($sourceId,$job);
        $html='<section class="section r07-job-summary"><h2>求人情報</h2>';
        $html.='<p class="note">仕事内容・給与・勤務地・応募条件をまとめました。別年度・別採用コースの条件は同じ募集に自動適用しません。掲載情報は現在の募集受付・応募資格を保証しません。</p>';
        foreach($data as $group=>$entries){
            $html.='<section class="r07-job-group"><h3>'.Security::h($group).'</h3><div class="r07-table-wrap"><table class="r07-fact-table"><tbody>';
            foreach($entries as $label=>$values){
                if($values===[])continue;
                $html.='<tr><th scope="row">'.Security::h($label).'</th><td><ul class="r07-job-values">';
                foreach($values as $v)$html.='<li>'.Security::h($v['value']).(preg_match('/勤務地|配属先|住所/u',$label)?GoogleMapsLink::button($v['value']):'').'</li>';
                $html.='</ul></td></tr>';
            }
            $html.='</tbody></table></div></section>';
        }
        $html.='</section>';
        return $html;
    }
}
