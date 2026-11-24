<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Provenance-aware company comparison. Never mixes private drafts or assumed workplaces. */
final class CompanyCompareController {
    public static function isRoute(string $path): bool {return $path==='/companies/compare';}
    public static function selectedIds(mixed $raw): array {
        if($raw===null)return [];
        if(!is_array($raw)||count($raw)>10)throw new \InvalidArgumentException('Select at most ten companies');
        $ids=[];
        foreach($raw as $value){
            if(!is_string($value))throw new \InvalidArgumentException('Invalid comparison selection');
            $id=BusinessData::id($value);
            if(in_array($id,$ids,true))throw new \InvalidArgumentException('Duplicate company');
            $ids[]=$id;
        }
        return $ids;
    }
    public static function handle(string $path,string $method): void {
        if($method!=='GET'){http_response_code(405);header('Allow: GET');Kernel::layout('企業比較','<p>GETから企業を選択してください。</p>');return;}
        try {$ids=self::selectedIds($_GET['ids']??null);}
        catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('企業比較','<p>比較対象は公開企業から10社以内を選んでください。</p>');return;}
        $db=Database::connected();$selected=[];
        foreach($ids as $id){
            $company=BusinessRepository::read($db,'companies',$id,true);
            if(!$company){http_response_code(404);Kernel::layout('企業比較','<p>公開された企業のみ比較できます。</p>');return;}
            $selected[]=['company'=>$company,'jobs'=>BusinessRepository::jobsForCompany($db,$id,true),
                'locations'=>BusinessRepository::list($db,'company_locations',true,100,0,$id),
                'sources'=>BusinessRepository::sourcesForCompany($db,$id),'facts'=>CompanyFacts::list($db,$id,true)];
        }
        try {$filter=WorkspaceRepository::normalizeFilters(['q'=>$_GET['q']??'']);}
        catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('企業比較','<p>検索語が正しくありません。</p>');return;}
        $choices=BusinessSearch::find($db,'companies',$filter,100,0);
        Kernel::layout('企業比較',self::render($choices,$selected,$filter['q']??''));
    }
    public static function render(array $choices,array $selected,string $q=''): string {
        $h='<section class="hero compact"><span class="eyebrow">COMPANY COMPARISON</span><h1>企業を比べる</h1><p>公開企業を最大10社並べて比較します。募集状況、勤務地、会社所在地は別の情報です。</p></section>';
        $h.='<details class="compare-drawer"><summary>比較する企業（最大10件）</summary><form method="get" action="/companies/compare" class="setup-form compare-picker"><label>企業名で絞り込む<input name="q" maxlength="100" value="'.Security::h($q).'"></label><details class="compare-dropdown"><summary>企業をドロップダウンから選択</summary><div class="compare-dropdown-list">';
        if(!$choices)$h.='<p>公開済みの企業はまだありません。</p>';
        $chosen=array_map(static fn(array $v):string=>(string)$v['company']['id'],$selected);
        foreach($choices as $c){
            $id=BusinessData::id((string)$c['id']);
            $h.='<label class="compare-choice"><input type="checkbox" name="ids[]" value="'.Security::h($id).'"'.(in_array($id,$chosen,true)?' checked':'').'> '.Security::h((string)$c['display_name']).'</label>';
        }
        if(count($choices)===100)$h.='<p class="note">検索条件に一致する企業は先頭100社まで表示します。さらに絞り込んでください。</p>';
        $h.='</div></details><button type="submit" class="btn primary">比較表を表示</button></form></details>';
        if(!$selected){$h.='<p class="note">比較対象を選択すると、確認済み出典を区別した比較表が表示されます。</p>';return $h;}
        $h.='<section class="section"><h2>比較結果</h2><div class="compare-scroll" role="region" tabindex="0" aria-label="企業比較表"><table class="compare-table"><thead><tr><th scope="col">確認項目</th>';
        foreach($selected as $v)$h.='<th scope="col">'.Security::h((string)$v['company']['display_name']).'</th>';
        $h.='</tr></thead><tbody>';
        $rows=[
            '法人名'=>static fn(array $v):string=>(string)($v['company']['legal_name']??'未確認'),
            '企業説明'=>static fn(array $v):string=>(string)($v['company']['description']??'情報未確認'),
            '公開出典数'=>static fn(array $v):string=>(string)count($v['sources']).'件',
            '公開求人'=>static fn(array $v):string=>(string)count($v['jobs']).'件（現在の採用残枠とは異なります）',
            '勤務地・拠点'=>static function(array $v):string {
                if(!$v['locations'])return '所在地・配属先は未確認';
                $names=[];foreach($v['locations'] as $r)$names[]=(string)$r['name'].'（'.($r['kind']==='headquarters'?'本社': '拠点候補').'・'.($r['verification_state']==='verified'?'確認済み':'位置未確定').'）';
                return implode('、',$names);
            },
            '出典付き待遇・財務・口コミ'=>static function(array $v):string {
                $rows=$v['facts']??[];if(!$rows)return '根拠付き公開記録なし';
                $claims=[];foreach($rows as $r)$claims[]=CompanyFacts::LABELS[$r['topic']].'：'.$r['label'].'＝'.$r['claim_text'].'（出典：'.$r['source_title'].'／'.$r['source_type'].'）';
                return implode('、',$claims);
            },
            '求人の募集状態'=>static function(array $v):string {
                if(!$v['jobs'])return '求人データなし';
                $states=[];foreach($v['jobs'] as $job){
                    $state=match($job['recruitment_state']){'confirmed_open'=>'情報確認日時点の受付確認','listed'=>'掲載あり・残枠未確認','closed'=>'終了','unverified'=>'未確認',default=>'未確認'};
                    $states[]=(string)$job['title'].'：'.$state.'（'.($job['observed_on']??'確認日未登録').'）';
                }
                return implode('、',$states);
            }
        ];
        foreach($rows as $label=>$getter){$values=array_map($getter,$selected);$same=count($values)>1&&count(array_unique(array_map('trim',$values)))===1;$h.='<tr class="'.($same?'compare-same':'compare-different').'"><th scope="row">'.Security::h($label).'</th>';foreach($values as $v)$h.='<td>'.Security::h($v).'</td>';$h.='</tr>';}

        $h.='<tr><th scope="row">企業詳細と出典</th>';foreach($selected as $v)$h.='<td><a href="/companies/'.Security::h((string)$v['company']['id']).'">詳細を確認する</a></td>';
        $h.='</tr></tbody></table></div><p class="note">本社住所を実勤務地として代用せず、求人掲載だけで募集中とは判断しません。必ず公開出典と確認日をご覧ください。</p></section>';
        return $h;
    }
}
