<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Read-only personal research on existing company/job directory pages.
 * No identity merging, public visibility changes, or live URL checks.
 */
final class PrivateResearchCatalogue {
    private const EVENTS=['initial:research:d1.0.1:held','initial:links:d1.0.2:held'];
    private const LIMIT=25;
    private const FIELDS=[
        'japanese'=>'企業・ブランド名','name'=>'資料内の企業名','company'=>'企業・候補名',
        'area'=>'資料掲載地域','role'=>'資料掲載職種','type'=>'雇用区分','graduates'=>'卒業要件',
        'foreign'=>'留学生に関する記載','age'=>'年齢条件','salary'=>'給与情報',
        'deadline'=>'資料掲載期限','strength'=>'資料掲載の特徴','risk'=>'留意事項',
        'status'=>'調査時点の掲載状況','classification'=>'資料内の区分',
        'entry_type'=>'応募導線の区分','notes'=>'原典の備考','checked_date'=>'資料の確認日',
        'priority'=>'研究優先度','url'=>'資料に収録されたURL','reference'=>'調査資料の参照キー',
    ];
    public static function render(PDO $db,string $context,array $request):string {
        // These routes can be enabled for guest browsing; never read held items for guests.
        if(Identity::current()===null)return '';
        $path=$context==='jobs'?'/jobs':'/companies';
        $html='<section class="section" aria-label="個人用の既存調査資料"><h2>個人用・既存調査資料</h2>'
            .'<p class="note">情報収集サブプロジェクトの既存資料をそのまま表示しています。原典の掲載情報は現在の募集枠・応募資格を保証しません。正式会社・求人データとの同一性は自動判定しません。この欄はログイン中だけ表示されます。</p>';
        if($context==='companies')$html.=self::draftCompanies($db);
        $id=$request['research_id']??null;
        if(is_string($id) && preg_match('/\Ar-[a-f0-9]{24}\z/D',$id)===1){
            $row=self::read($db,$id);
            $html.=$row!==null?self::details($row):'<p class="note">調査記録がありません。</p>';
            return $html.'<p><a href="'.$path.'#private-research" class="text-link">調査資料一覧へ戻る</a></p></section>';
        }
        if($id!==null)$html.='<p class="note">調査資料の指定形式が正しくありません。</p>';
        $search=$request['research_q']??'';
        $search=is_string($search)&&strlen($search)<=150&&preg_match('//u',$search)===1?trim($search):'';
        $page=$request['research_page']??'1';
        $page=is_string($page)&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$page)===1?min((int)$page,100):1;
        $html.='<div id="private-research"><form method="get" action="'.$path.'" role="search" class="directory-filters">'
            .'<label>既存調査資料を検索<input name="research_q" maxlength="50" value="'.Security::h($search).'" placeholder="企業名・職種・地域"></label>'
            .'<button class="btn primary" type="submit">資料を探す</button><a class="btn ghost" href="'.$path.'#private-research">条件をクリア</a></form>';
        $rows=self::list($db,$search,$page);
        if($rows===[])$html.='<p class="note">該当する調査資料はありません。</p>';
        foreach($rows as $row){
            $record=self::decode($row['source_record_json']??null);
            $title=self::value($record['japanese']??$record['company']??$record['name']??null,'名称未記録');
            $kind=($row['research_version']??'')==='d1.0.2'?'企業別リンク台帳':'企業・求人調査';
            $extra=self::value($record['role']??$record['area']??null,'');
            $html.='<article class="panel"><h3>'.Security::h($title).'</h3><p>'.Security::h($kind.' / '.$row['research_version']).'</p>';
            if($extra!=='')$html.='<p>'.Security::h($extra).'</p>';
            $html.='<p class="note">'.Security::h((string)$row['record_status']).'（調査時点の分類）</p>';
            $html.='<a class="text-link" href="'.$path.'?research_id='.rawurlencode((string)$row['review_id']).'">原典内容・関連URLを確認 →</a></article>';
        }
        $html.='<nav aria-label="個人調査資料のページ切替">';
        if($page>1)$html.='<a class="text-link" href="'.Security::h(self::pageUrl($path,$search,$page-1)).'">前へ</a> ';
        if(count($rows)===self::LIMIT)$html.='<a class="text-link" href="'.Security::h(self::pageUrl($path,$search,$page+1)).'">次へ</a>';
        return $html.'</nav></div></section>';
    }
    private static function draftCompanies(PDO $db):string {
        $q=$db->query("SELECT id,display_name,description FROM companies WHERE visibility='draft' AND seed_key IS NOT NULL AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='companies' AND r6.record_id=companies.id AND r6.seed_version='research-d1.0.4-R06') ORDER BY display_name LIMIT 25");
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)return '';
        $html='<h3>初期登録済み企業下書き（非公開）</h3><div class="cards">';
        foreach($rows as $row){
            if(!is_string($row['id']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$row['id'])!==1)continue;
            $html.='<article class="panel"><h3>'.Security::h(self::value($row['display_name']??null,'名称未記録')).'</h3>'
                .'<p>'.Security::h(self::value($row['description']??null,'')).'</p>'
                .'<a class="text-link" href="/companies/'.$row['id'].'">企業下書きの詳細 →</a></article>';
        }
        return $html.'</div>';
    }
    private static function baseQuery():string {
        return " FROM research_import_items i JOIN research_import_batches b ON b.id=i.batch_id"
            ." WHERE b.status='held_initial_setup' AND b.event_key IN ('initial:research:d1.0.1:held','initial:links:d1.0.2:held')";
    }
    private static function read(PDO $db,string $id):?array {
        $stmt=$db->prepare('SELECT i.*,b.research_version'.self::baseQuery().' AND i.review_id=? LIMIT 1');
        $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }
    private static function list(PDO $db,string $search,int $page):array {
        $where='';$args=[];
        if($search!==''){
            $where=" AND i.source_record_json LIKE ? ESCAPE '!'";
            $args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        }
        $offset=($page-1)*self::LIMIT;
        $sql='SELECT i.review_id,i.record_status,i.source_record_json,b.research_version'
            .self::baseQuery().$where.' ORDER BY b.research_version,i.review_id LIMIT '.self::LIMIT.' OFFSET '.$offset;
        $stmt=$db->prepare($sql);$stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function details(array $row):string {
        $record=self::decode($row['source_record_json']??null);
        if($record===[])return '<p class="note">調査記録の表示形式が正しくありません。原典を変更せず管理者へ確認してください。</p>';
        $title=self::value($record['japanese']??$record['company']??$record['name']??null,'名称未記録');
        $html='<article class="panel"><h3>'.Security::h($title).'</h3><p>研究データ版：'.Security::h((string)$row['research_version'])
            .' ／ 技術レコードキー：'.Security::h((string)$row['review_id'])
            .' ／ 保存時分類：'.Security::h((string)$row['record_status']).'</p>';
        foreach(self::FIELDS as $key=>$label){
            if($key==='url' || !array_key_exists($key,$record))continue;
            $v=$record[$key];if(!is_string($v)&&!is_numeric($v))continue;
            $html.='<p><strong>'.Security::h($label).'：</strong>'.Security::h((string)$v).'</p>';
        }
        if(ResearchExternalLinks::safeUrl($record['url']??null))
            $html.='<p><a href="'.Security::h($record['url']).'" target="_blank" rel="noopener noreferrer nofollow external">原典の参照URL（外部サイト）</a></p>';
        $links=$record['links']??null;
        if(is_array($links)){
            $html.='<h4>原典の収録リンク</h4>';
            foreach(ResearchExternalLinks::TYPES as $kind=>$label){
                $urls=$links[$kind]??[];
                if(!is_array($urls))continue;
                foreach($urls as $n=>$url){
                    if(!ResearchExternalLinks::safeUrl($url))continue;
                    $statuses=$record['source_status'][$kind]??[];
                    $status=is_array($statuses)?self::value($statuses[$n]??null,''):'';
                    $html.='<p><a target="_blank" rel="noopener noreferrer nofollow external" href="'.Security::h($url).'">'.Security::h($label).'（外部サイト）</a>';
                    if($status!=='')$html.=' — '.Security::h($status);
                    $html.='</p>';
                }
            }
        }
        $html.='<p class="note">原典SHA256：'.Security::h((string)$row['source_record_sha256']).'</p>';
        return $html.'</article>';
    }
    private static function decode(mixed $raw):array {
        if(!is_string($raw)||strlen($raw)>100000)return [];
        try {$data=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(\JsonException){return [];}
        return is_array($data)?$data:[];
    }
    private static function value(mixed $value,string $fallback):string {
        return is_string($value)?$value:(is_numeric($value)?(string)$value:$fallback);
    }
    private static function pageUrl(string $path,string $search,int $page):string {
        return $path.'?'.http_build_query(['research_q'=>$search,'research_page'=>$page]).'#private-research';
    }
}
