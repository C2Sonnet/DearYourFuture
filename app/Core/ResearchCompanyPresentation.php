<?php
 declare(strict_types=1);
 namespace KMM\Core;
 /** Human-facing company facts. Research indexes remain immutable. */
 final class ResearchCompanyPresentation {
    private const FIELDS=[
       'legal_name'=>'法人名','brand'=>'ブランド','sector'=>'業種','business_history'=>'事業概要',
       'financial_status'=>'財務状況','major_clients'=>'主要取引先','operator_name'=>'運営主体',
       'legal_employer'=>'雇用主の記録','identity_status'=>'法人の確認状況','recruitment_status'=>'採用情報',
       'hq_address'=>'本社所在地','full_address'=>'本社所在地','postal_code'=>'郵便番号',
       'scope_note'=>'所在地に関する注意事項','summary'=>'社員紹介の内容','note'=>'調査内容の補足',
       'employee_review'=>'社員口コミ','positive_review'=>'良かった点の口コミ','negative_review'=>'気になった点の口コミ',
       'worksite'=>'勤務予定地','worksite_address'=>'勤務予定地','nearest_station'=>'最寄駅',
       'salary'=>'給与の記録','salary_components'=>'給与内訳','registered_name'=>'登記上の法人名','registered_legal_name'=>'登記上の法人名','registered_office'=>'登記本店','corporate_number'=>'法人番号','registered_office_address'=>'登記本店','finding'=>'確認内容',
    ];
    private static function friendly(mixed $raw):?string {
        if(!is_string($raw)&&!is_numeric($raw))return null;
        $value=trim((string)$raw);
        if($value==='' || in_array(strtolower($value),['null','none','n/a','true','false'],true))return null;
        $statuses=[
          'published'=>'掲載を確認しています（現在の募集状況は未確認）',
          'not_yet_verified_for_individual'=>'応募者本人への適用条件は未確認です',
          'unverified'=>'まだ確認できていません',
          'not_verified'=>'まだ確認できていません',
          'search_index_snippet_only'=>'検索結果の抜粋だけが確認できています',
          'D_public_search_excerpt_only_1_positive'=>'公開検索結果の抜粋に肯定的な口コミ1件が見つかりました。投稿全文や現在の職場環境は未確認です',
        ];
        if(strtolower($value)==='unknown')return '不明';
        if(isset($statuses[$value]))return $statuses[$value];
        if(preg_match('/\A[A-Z]_[a-z0-9_]+(?:_[0-9]+_[a-z0-9_]+)?\z/',$value)){
           if(str_contains($value,'excerpt'))return '公開情報の一部のみ確認しました。詳細は出典での確認が必要です';
           return '確認状況が限定された調査記録です。詳細は未確認です';
        }
        if(preg_match('/\A[a-z][a-z0-9_]*(?:_[a-z0-9_]+)+\z/',$value))return null;
        if(preg_match('/\A(?:https?:\/\/|[a-f0-9]{32,64}\z)/i',$value))return null;
        return $value;
    }
    public static function entries(string $sourceId,array $co):array {
        $rows=[];
        foreach([
          '法人名'=>$co['legal_name']??null,'ブランド'=>$co['brand']??null,'業種'=>$co['sector']??null,
          '事業概要'=>$co['company_details']['business_history']??null,
          '財務状況'=>$co['company_details']['financial_status']??null,
          '主要取引先'=>$co['company_details']['major_clients']??null,
          '採用情報'=>$co['recruitment_status']??null,
          '本社所在地'=>ResearchR07::address($co),
        ] as $label=>$value){$v=self::friendly($value);if($v!==null)$rows[$label][$v]=true;}
        foreach(ResearchR07FieldIndex::rows('companies',$sourceId) as $item){
            if(!is_array($item))continue;
            $name=(string)($item['field']??preg_replace('/^.*\./','',(string)($item['path']??'')));
            if(!isset(self::FIELDS[$name]))continue;
            $v=self::friendly($item['value']??null);if($v===null)continue;
            $rows[self::FIELDS[$name]][$v]=true;
        }
        return $rows;
    }
    public static function render(string $sourceId,array $co):string {
        $entries=self::entries($sourceId,$co);
        $html='<section class="section r07-company-summary"><h2>会社情報</h2><p class="note">公開資料に基づく収録時点の情報です。勤務地、募集受付、応募資格は求人ごとに確認してください。</p><div class="r07-table-wrap"><table class="r07-fact-table"><tbody>';
        foreach($entries as $label=>$values){$known=array_diff_key($values,array_fill_keys(['不明','未確定','未確認','種類不明'],true));$mixed=$known&&count($known)<count($values);if($mixed)$values=$known;$html.='<tr><th scope="row">'.Security::h($label).'</th><td>'.Security::h(implode(' ／ ',array_keys($values))).(preg_match('/所在地|住所|勤務地|勤務予定地/u',$label)?GoogleMapsLink::button((string)(array_key_first($values)??'')):'').($mixed?'<p class="note">不明・未確定とする記録もあります。再確認が必要です。</p>':'').'</td></tr>';}
        return $html.'</tbody></table></div></section>'.ResearchClassification::render('companies',$sourceId);
    }
 }
