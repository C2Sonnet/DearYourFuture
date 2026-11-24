<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** R07-source comparison pages; selections are per-browser-tab, not written to other users' DB. */
final class ComparisonCarts {
    public static function isRoute(string $path):bool {return in_array($path,['/compare/companies','/compare/jobs','/companies/compare'],true);}
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){http_response_code(405);Kernel::layout('比較','<p>閲覧専用です。</p>');return;}
        if(Identity::current()===null){header('Location: /account/login?next='.rawurlencode($path),true,302);return;}
        $db=Database::connected();$kind=$path==='/compare/jobs'?'jobs':'companies';
        try{$ids=self::selected($kind,$_GET['ids']??[]);}catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('比較','<p>比較対象は最大10件まで選んでください。</p>');return;}
        $html='<section class="hero compact"><h1>'.($kind==='companies'?'企業':'求人').'を比べる</h1><p>最大10件まで並べて比較します。元資料の記録値は確認状況にかかわらず保持し、存在しない値は空欄にします。</p></section>';
        $selected=[];foreach($ids as $id){$row=$kind==='companies'?ResearchR07::company($id):ResearchR07::job($id);if($row!==null)$selected[$id]=$row;}
        $html.='<details class="compare-drawer"><summary>比較する'.($kind==='companies'?'企業':'求人').'（最大10件） <span class="compare-drawer-count">'.count($selected).'件選択中</span></summary><form class="compare-picker" action="'.$path.'" method="get"><details class="compare-dropdown"><summary>比較対象をドロップダウンから選択</summary><div class="compare-dropdown-list" role="group" aria-label="比較する項目">';
        $records=$kind==='companies'?ResearchR07::companies():ResearchR07::jobs();
        foreach($records as $id=>$row){$name=$kind==='companies'?ResearchR07::displayName($row):ResearchR07::jobTitle($row);
            $html.='<label class="compare-choice"><input type="checkbox" name="ids[]" value="'.Security::h($id).'"'.(isset($selected[$id])?' checked':'').'>'.Security::h($name).' <small>'.Security::h($id).'</small></label>';
        }
        $html.='</div></details><button class="btn primary" type="submit">選んだ項目を比較する</button><button class="btn ghost" type="button" data-compare-clear="'.$kind.'">比較対象を一括で外す</button></form></details>'; 
        if($selected!==[]){
            $fields=$kind==='companies'?[
                '会社所在地'=>fn($x)=>ResearchR07::localArea($x),
                '住所'=>fn($x)=>ResearchR07::address($x),
                '業種'=>fn($x)=>ResearchR07::firstValue($x['sector']??null),
                '最寄駅・副都心・雇用形態'=>fn($x)=>ResearchR07::quickFacts($x),
                '求人件数'=>fn($x)=>(string)count(ResearchR07::companyJobs((string)$x['company_id'])),
                '会社概要'=>fn($x)=>ResearchR07::firstValue($x['company_details']['business_history']??null),
                '財務状況'=>fn($x)=>ResearchR07::firstValue($x['company_details']['financial_status']??null),
            ]:[
                '企業名'=>fn($x)=>ResearchR07::displayName(ResearchR07::company((string)$x['company_id'])??[]),
                '雇用形態'=>fn($x)=>ResearchR07::firstValue($x['employment_type']??null),
                '勤務地'=>fn($x)=>ResearchR07::firstValue($x['worksite']??null),
                '最寄駅'=>fn($x)=>ResearchR07::firstStation($x),
                '副都心'=>fn($x)=>ResearchR07::firstValue($x['commute']['hub']??null),
                '最短電車時間'=>fn($x)=>($v=ResearchR07::rail($x))!==null?($v['line']??'電車').' '.$v['minutes'].'分':null,
                '採用年度'=>fn($x)=>ResearchR07::firstValue($x['recruitment_year']??null),
                '給与原文'=>fn($x)=>ResearchR07::firstValue($x['recheck_r07']['salary_components']??null),
            ];
            $fields['専門技術との適合性分類']=static fn($x)=>ResearchClassification::pairs($kind,(string)($x[$kind==='companies'?'company_id':'job_id']??''))[$kind==='companies'?'専門技術との適合性分類（収録求人）':'専門技術との適合性分類']??null;
            foreach(['応募確認候補の分類','応募分類の理由','掲載月額（参考）','年間休日（日）','勤務時間','固定残業時間','固定残業代','待遇の注意点','個別応募資格'] as $label){$fields[$label]=static fn($x)=>ResearchClassification::pairs($kind,(string)($x[$kind==='companies'?'company_id':'job_id']??''))[$label]??null;}
            $html.='<div class="compare-scroll" role="region" tabindex="0" aria-label="比較表"><table class="compare-table"><thead><tr><th scope="col">比較項目</th>';
            foreach($selected as $id=>$row)$html.='<th scope="col">'.Security::h($kind==='companies'?ResearchR07::displayName($row):ResearchR07::jobTitle($row)).'</th>';
            $html.='</tr></thead><tbody>';
            foreach($fields as $label=>$fn){
                $values=[];foreach($selected as $row)$values[]=(string)($fn($row)??'—');
                if(count(array_filter($values,static fn($v)=>$v!=='—'&&trim($v)!==''))===0)continue;
                $canonical=array_map(static fn(string $v):string=>preg_replace('/\s+/u','',trim($v))??trim($v),$values);
                $same=count($canonical)>1 && count(array_unique($canonical))===1;
                $html.='<tr class="'.(count($values)<2?'compare-single':($same?'compare-same':'compare-different')).'"><th scope="row">'.Security::h($label).'</th>';
                foreach($values as $value)$html.='<td>'.Security::h($value).'</td>';
                $html.='</tr>';
            }
            $html.='<tr><th scope="row">詳細情報</th>';foreach($selected as $id=>$row)$html.='<td><a class="btn ghost" href="'.Security::h($kind==='companies'?ResearchR07::companyUrl($db,$id):ResearchR07::jobUrl($db,$id)).'">詳細を見る</a></td>';$html.='</tr></tbody></table></div>';
        }
        Kernel::layout($kind==='companies'?'企業比較':'求人比較',$html);
    }
    public static function selected(string $kind,mixed $raw):array {
        if(is_string($raw))$raw=$raw===''?[]:explode(',',$raw);
        if(!is_array($raw)||count($raw)>10)throw new \InvalidArgumentException('Too many selection IDs');
        $ids=[];foreach($raw as $id){if(!is_string($id)||!($kind==='companies'?ResearchR07::isCompanyId($id):ResearchR07::isJobId($id)))throw new \InvalidArgumentException('Invalid selection ID');$ids[$id]=true;}
        return array_keys($ids);
    }
}
