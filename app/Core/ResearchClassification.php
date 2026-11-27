<?php
declare(strict_types=1);
namespace KMM\Core;
final class ResearchClassification {
    private static ?array $index=null;
    public const ROLE=['R1_商品開発明示'=>'商品開発が明示されている','R2_製菓製造'=>'製菓製造','R3_製パン'=>'製パン','R4_食品工程品質'=>'食品工程・品質管理','R5_製造兼務配属未確定'=>'製造兼務・配属未確定','R6_隣接食品製造'=>'隣接する食品製造','R7_職種対象外'=>'対象外の職種','R8_職種未確定'=>'職種未確定'];
    public const CANDIDATE=['A_優先応募確認候補'=>'優先応募確認候補','B_条件付き候補'=>'条件付き候補','C_対象外・旧募集終了'=>'対象外・旧募集終了','D_HOLD'=>'確認保留'];
    public static function data():array {
        if(self::$index!==null)return self::$index;
        $h=ResearchD200::projection();$comp=$h;$out=['jobs'=>[],'companies'=>[],'comparison'=>[],'summary'=>$h['summary']];
        foreach($h['jobs'] as $v)foreach(explode(';',$v['raw_job_ids']) as $id)$out['jobs'][$id]=$v;
        foreach($h['companies'] as $v)foreach(explode(';',$v['raw_company_ids']) as $id)$out['companies'][$id]=$v;
        foreach($comp['comparison'] as $v)foreach(explode(';',$v['raw_job_ids']) as $id)$out['comparison'][$id]=$v;
        return self::$index=$out;
    }
    public static function companyJobs(string $id):array {
        $co=self::data()['companies'][$id]??null;if(!$co)return ResearchR07::companyJobs($id);
        $ids=explode(';',(string)$co['raw_company_ids']);return array_values(array_filter(ResearchR07::jobs(),static fn($j)=>in_array($j['company_id']??null,$ids,true)));
    }
    public static function options():array {return ['role_class'=>self::ROLE,'application_class'=>self::CANDIDATE];}
    public static function matches(array $job,array $f):bool {
        $v=self::data()['jobs'][$job['job_id']??'']??[];
        foreach(self::options() as $key=>$options)if(isset($f[$key])&&($v[$key]??null)!==$f[$key])return false;
        return true;
    }
    private static function clean(mixed $v):?string {
        if($v===null||$v==='')return null;if(is_bool($v))return $v?'確認済み':'未確認';
        if(!is_scalar($v))return null;$s=trim((string)$v);if($s===''||strtolower($s)==='null')return null;
        if(in_array(strtolower($s),['false','unreviewed','unknown','not_verified','unverified'],true))return '未確認';
        // Strip machine field path prefixes from evidence text, not the actual evidence content.
        $s=preg_replace('/(?:[a-z][a-z0-9_]*\.)+[a-z][a-z0-9_]*:\s*/i','',$s)??$s;
        return $s;
    }
    public static function pairs(string $kind,string $id):array {
        $d=self::data();$x=$d[$kind][$id]??[];if(!$x)return [];
        if($kind==='companies'){$roles=[];foreach(self::companyJobs($id) as $j){$v=$d['jobs'][$j['job_id']]['role_class']??null;if(isset(self::ROLE[$v]))$roles[self::ROLE[$v]]=true;}return ['専門技術との適合性分類（収録求人）'=>implode(' ／ ',array_keys($roles)),'応募確認候補の分類'=>self::CANDIDATE[$x['candidate_class']]??'未確定','優先応募確認候補の求人数'=>$x['priority_confirmation'],'条件付き候補の求人数'=>$x['conditional'],'対象外・旧募集終了の求人数'=>$x['excluded'],'確認保留の求人数'=>$x['hold'],'確認が必要な項目'=>self::clean($x['hold_categories']??null),'個別応募資格'=>$x['eligibility']??null];}
        $p=['専門技術との適合性分類'=>self::ROLE[$x['role_class']]??'未確定','分類根拠'=>$x['role_basis']??null,'応募確認候補の分類'=>self::CANDIDATE[$x['application_class']]??'未確定','応募分類の理由'=>$x['classification_reason']??null,'初期雇用形態'=>$x['registered_initial_employment']??null,'公開募集情報'=>$x['public_status_record']??null,'最新の確認内容'=>$x['latest_batch_note']??null,'確認保留の項目'=>self::clean($x['job_hold_categories']??null),'個別応募資格'=>$x['actual_eligibility']??null,'採用残枠'=>($x['active_slots_verified']??null)===true?'確認済み':'未確認','本人の在留資格'=>($x['personal_visa_verified']??null)===true?'確認済み':'未確認','初期配属'=>($x['initial_assignment_guaranteed']??null)===true?'確認済み':'未確定','厳密な40分通勤'=>($x['strict_40min_verified']??null)===true?'確認済み':'未確認'];
        if(($x['batch79_freshness_attempted']??null)===true)$p['公開情報の点検']='点検実施済み（現在受付の認定ではありません）';
        $p['現在の受付確認']=($x['current_receipt_verified']??null)===true?'確認済み':'未認定';
        $p['本人確認']='調査対象外（N/A）';
        // Keep later notes on their own evidence/course boundaries. Do not promote them to verified facts.
        $extra=[];foreach([$x,$d['comparison'][$id]??[]] as $row)foreach($row as $k=>$v)if(is_string($v)&&$v!==''&&preg_match('/\Abatch(8[0-9]|9[0-9]|1[0-4][0-9])_note\z/D',$k,$m))$extra[(int)$m[1]]=$v;
        krsort($extra);foreach($extra as $batch=>$note)$p['追加調査記録 Batch'.$batch]=$note;
        $raw=ResearchR07::job($id);$rawForm=ResearchR07::firstValue($raw['employment_type']??null);
        if(in_array($rawForm,['正社員','契約社員'],true)&&in_array($p['初期雇用形態'],['種類不明','不明','未確定'],true)){
            $p['雇用形態（掲載記録）']=$rawForm;$p['雇用形態の補足']='初期雇用形態が不明とする資料もあります。入社時の契約条件を再確認してください。';
        }
        $v=$d['comparison'][$id]??[];
        foreach(['monthly_pay_record'=>'掲載月額（参考）','reference_amount_scope'=>'給与比較上の条件','fixed_overtime_hours_record'=>'固定残業時間','fixed_overtime_jpy_record'=>'固定残業代','annual_holidays_record'=>'年間休日（日）','work_time_record'=>'勤務時間','terms_caveats'=>'待遇の注意点','foreign_student_requirements_record'=>'外国籍・応募資格の記録','worksite_detail_records'=>'勤務地の詳細','verification_status'=>'全条件の確認状況'] as $key=>$label){$value=self::clean($v[$key]??null);if($value!==null)$p[$label]=$value;}
        return $p;
    }
    public static function render(string $kind,string $id):string {
        $p=self::pairs($kind,$id);if(!$p)return '';
        return '<section class="section"><h2>専門技術・応募条件の比較資料</h2><p class="note">公開資料に基づく分類です。実際の採用枠、個人の応募資格、配属の確定を意味しません。原典の異なる給与やコースを合算しません。</p>'.ResearchR07::pairTable($p).'</section>';
    }
}
