<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Read-only display of the Batch21 supplementary CSV evidence ledgers.
 * An interview published by an employer is deliberately NOT an independent employee review.
 * This class never updates MySQL or reclassifies vacancies as open.
 */
final class ResearchR07SupplementalIndex {
    private const INDEX_SHA256 = '0a965ed905049a8f242630daa4ec78bb6e278f204dde607f7d59a5a00664df91';
    private static ?array $data = null;

    private static function get():array {
        if (self::$data !== null) return self::$data;
        $path = SiteState::root().'/resources/research/r07-batch22/RESEARCH_SUPPLEMENTAL_INDEX_d1.0.4_R07_BATCH22.json';
        if (!is_file($path) || !hash_equals(self::INDEX_SHA256,(string)hash_file('sha256',$path))) {
            throw new \RuntimeException('R07 supplemental index checksum mismatch');
        }
        $data = json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data['companies']??[]) !== 108 || count($data['jobs']??[]) !== 137) {
            throw new \RuntimeException('R07 supplemental index record mismatch');
        }
        return self::$data=$data;
    }
    private static function fieldName(string $key):string {
        $dictionary=[
            'existing_source_url'=>'元求人URL', 'source_url'=>'出典URL',
            'graduation_and_school_criteria'=>'卒業年度・学歴条件',
            'foreign_nationality_and_visa_conditions'=>'外国籍・在留資格の公開条件',
            'technical_duties_and_sales_risk'=>'技術職の業務と販売兼務の記録',
            'initial_workplace_evidence'=>'初期勤務地の出典記録',
            'salary_benefits_2027_job_if_verified'=>'2027卒給与・福利厚生',
            'current_application_vacancy_status'=>'応募受付・残枠の確認記録',
            'exact_40min_door_to_door'=>'ドアツードア40分の検証',
            'eligibility_and_priority_final'=>'応募資格の総合判断',
            'unresolved_blockers'=>'未解決の条件',
            'employment_type_in_master'=>'原典記録の雇用形態',
            'public_recruitment_status'=>'公開求人の掲載・募集記録',
            'remaining_slots_verified'=>'残採用枠の確認',
            'door_to_door_40min_verified'=>'40分通勤の確認',
            'salary_components'=>'給与内訳',
            'worksite_verified_address'=>'勤務地の住所',
            'nearest_station'=>'最寄駅', 'walk_minutes'=>'駅徒歩（分）',
            'hq_address'=>'本店・本社所在地','postal_code'=>'郵便番号',
            'verification'=>'確認区分','operator_name'=>'運営主体名',
            'identity_status'=>'法人識別の記録','legal_employer'=>'法人・雇用主体',
            'speaker'=>'インタビュー掲載者','role'=>'職種',
            'summary'=>'社員紹介の内容（企業発信）',
            'counted_as_independent_review'=>'独立社員口コミとしての計上有無',
            'note'=>'原典注記', 'checked_on'=>'原典の確認日',
        ];
        return $dictionary[$key] ?? '原典項目：'.str_replace('_',' ',$key);
    }
    /** Complete internal index rows; consumer filters to applicant-relevant fields. */
    public static function rows(string $kind,string $sourceId):array {
        if (!in_array($kind,['companies','jobs'],true))return [];
        $entry=self::get()[$kind][$sourceId]??null;
        if (!is_array($entry))return [];
        $rows=[];foreach(($entry['groups']??[]) as $group=>$items){
            if(!is_array($items))continue;
            foreach($items as $item)if(is_array($item))$rows[]=$item;
        }
        return $rows;
    }
    public static function render(string $kind,string $sourceId):string {
        if (!in_array($kind,['companies','jobs'],true)) return '';
        if ($kind==='companies' ? !ResearchR07::isCompanyId($sourceId) : !ResearchR07::isJobId($sourceId)) return '';
        $entry=self::get()[$kind][$sourceId]??null;
        if (!is_array($entry)) return '';
        $groups=$entry['groups']??[];$links=$entry['links']??[];
        if ((!is_array($groups)||$groups===[]) && (!is_array($links)||$links===[])) return '';
        $html='<section class="section r07-supplemental-index"><h2>追加調査・確認台帳（R07 Batch22）</h2>'
             .'<p class="note">原典・監査台帳で記録された値を、未確認でもそのまま掲載します。募集受付中・本人の応募可能性・条件の適用が確定したことを意味しません。企業発信の社員紹介と独立した社員口コミは区別します。</p>';
        foreach ($groups as $group=>$rows) {
            if (!is_string($group)||!is_array($rows)||$rows===[]) continue;
            $html.='<details class="r07-field-group"><summary>'.Security::h($group).'（'.count($rows).'項目）</summary>';
            $html.='<div class="r07-table-wrap"><table class="r07-fact-table"><tbody>';
            foreach ($rows as $entry) {
                if (!is_array($entry)||!is_string($entry['field']??null)||!is_string($entry['value']??null))continue;
                $value=trim($entry['value']);if($value==='')continue;
                $html.='<tr><th scope="row">'.Security::h(self::fieldName($entry['field'])).'</th><td>'
                     .Security::h($value).'<small class="r07-field-source">'.Security::h((string)($entry['source']??'R07 Batch22')).'</small>'
                     .'</td></tr>';
            }
            $html.='</tbody></table></div></details>';
        }
        if (is_array($links)&&$links!==[]) {
            $html.='<details class="r07-field-group"><summary>追加の参照先（'.count($links).'件）</summary><div class="r07-source-links">';
            foreach($links as $link) {
                if(!is_array($link)||!ResearchExternalLinks::safeUrl($link['url']??null))continue;
                $html.='<a class="btn ghost external-link" href="'.Security::h($link['url']).'" target="_blank" rel="noopener noreferrer nofollow external">'.Security::h((string)($link['source']??'原典')).' ↗</a>';
            }
            $html.='</div></details>';
        }
        return $html.'</section>';
    }
}
