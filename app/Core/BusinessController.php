<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Read only SSR business pages, scoped by published company and verified provenance. */
final class BusinessController {
    public static function isRoute(string $path):bool {
        return in_array($path,['/companies','/jobs','/library/sources'],true)
            || preg_match('~\A/companies/[a-f0-9]{32}(?:/locations)?\z~D',$path)===1
            || preg_match('~\A/jobs/[a-f0-9]{32}\z~D',$path)===1
            || preg_match('~\A/(?:companies/research/CO|jobs/research/JOB)-[0-9]{4}\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){header('Allow: GET');self::message('許可されない操作',405);return;}
        $db=Database::connected();
        if($path==='/companies'){self::companies($db);return;}
        if($path==='/jobs'){self::jobs($db);return;}
        if($path==='/library/sources'){self::sources($db);return;}
        if(preg_match('~\A/companies/research/(CO-[0-9]{4})\z~D',$path,$m)){
            if(Identity::current()===null){self::message('ログインが必要です',401);return;}
            if(ResearchR07::company($m[1])===null){self::message('企業が見つかりません',404);return;}
            Kernel::layout(ResearchR07::displayName(ResearchR07::company($m[1])),EntityPageDecor::decorate($db,'companies',$m[1],ResearchR07::companyDetails($db,$m[1],true)));return;
        }
        if(preg_match('~\A/jobs/research/(JOB-[0-9]{4})\z~D',$path,$m)){
            if(Identity::current()===null){self::message('ログインが必要です',401);return;}
            if(ResearchR07::job($m[1])===null){self::message('求人が見つかりません',404);return;}
            Kernel::layout(ResearchR07::jobTitle(ResearchR07::job($m[1])),EntityPageDecor::decorate($db,'jobs',$m[1],ResearchR07::jobDetails($db,$m[1],true)));return;
        }
        if(preg_match('~\A/companies/([a-f0-9]{32})(/locations)?\z~D',$path,$m)) {self::company($db,$m[1],isset($m[2]));return;}
        if(preg_match('~\A/jobs/([a-f0-9]{32})\z~D',$path,$m)){self::job($db,$m[1]);return;}
        self::message('ページがありません',404);
    }
    private static function companies(PDO $db):void {
        $page=self::page();$filters=WorkspaceRepository::normalizeFilters($_GET);
        $all=DirectoryListing::all($db,'companies',$filters);
        [$page,$last,$rows,$total]=DirectoryListing::pages($all,$page);
        $html='<section class="hero compact"><span class="eyebrow">COMPANY DIRECTORY</span><h1>企業を探す</h1><p>求人と企業の収録記録をまとめて検索できます。勤務地や募集受付は原典で確認してください。</p></section>';
        $html.=BusinessSearch::form('companies',$filters);
        $uid=Identity::current()!==null?(int)Identity::current()['id']:null;
        $favorites=$uid!==null?WorkspaceRepository::favoriteKeys($db,$uid,'companies'):[];
        $companyIds=array_values(array_map(static fn(array $i):string=>$i['id'],array_filter($rows,static fn(array $i):bool=>$i['origin']==='db')));
        $myTracks=$uid!==null&&$companyIds!==[]?ApplicationRepository::companyTrackIndex($db,$uid,$companyIds):[];
        $html.='<div class="cards directory-results"><p class="directory-page-info">'.Security::h(DirectoryListing::summary($total,$page)).'</p>';
        foreach($rows as $item){
            $r=$item['row'];$sourceOnly=$item['origin']==='research';$id=$item['id'];
            $source=$sourceOnly?$id:ResearchR07::sourceId($db,'companies',$id);
            $co=$source!==null?ResearchR07::company($source):null;
            $title=$co!==null?ResearchR07::displayName($co):($sourceOnly?ResearchR07::displayName($r):(string)$r['display_name']);
            $url=$sourceOnly?ResearchR07::companyUrl($db,$id):'/companies/'.$id;
            $html.='<article class="panel"><h2>'.Security::h($title).'</h2>';
            if($co!==null)$html.='<p class="r07-card-facts">'.Security::h(ResearchR07::quickFacts($co)).'</p>';
            $html.='<div class="company-card-actions"><a class="btn ghost" href="'.Security::h($url).'">詳細・勤務地</a>';
            if($source!==null)$html.=ResearchR07::compareButton('companies',$source);
            if($uid!==null)$html.=WorkspaceController::favoriteButton($id,in_array($id,$favorites,true),BusinessSearch::paginationUrl('/companies',$filters,$page),'companies');
            $html.='</div>';
            if($uid!==null&&!$sourceOnly)$html.=ApplicationController::statusForm($id,null,$myTracks[$id]??null);
            $html.='</article>';
        }
        if($total===0)$html.='<p class="note">条件に合う企業がありません。</p>';
        $html.='</div>'.DirectoryListing::pagination('/companies',$filters,$page,$last,$total);
        if($uid!==null)$html.='<p><a class="text-link" href="/contributions/new?type=companies&amp;action=create">掲載されていない企業を提案する →</a></p>';
        Kernel::layout('企業を探す',$html);
    }
    private static function company(PDO $db,string $id,bool $locationsOnly):void {
        $r06=PrivateR06View::snapshot($db,'companies',$id);
        $r=$r06!==null?BusinessRepository::read($db,'companies',$id,false):BusinessRepository::read($db,'companies',$id,true);
        if(!$r && Identity::current()!==null){
            $private=BusinessRepository::read($db,'companies',$id,false);
            if($private!==null && ($private['visibility']??null)==='draft' && is_string($private['seed_key']??null) && $private['seed_key']!=='')$r=$private;
        }
        if(!$r||($r['visibility']??null)==='archived'){self::message('企業が見つかりません',404);return;}
        if($r06!==null){$body=PrivateR06View::companyDetail($db,$r,$r06,$locationsOnly);$me=Identity::current();if($me!==null&&!$locationsOnly)$body.=WorkspaceController::favoriteButton($id,in_array($id,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'companies'),true),'/companies/'.$id,'companies');$liveCo=ResearchR07::company((string)($r06['source_company_id']??''));Kernel::layout($liveCo!==null?ResearchR07::displayName($liveCo):(string)$r['display_name'],EntityPageDecor::decorate($db,'companies',$id,$body));return;}
        if(($r['visibility']??null)==='draft'){
            $html='<section class="hero compact"><h1>'.Security::h($r['display_name']).'</h1><p>個人用企業下書き（未確認の原典情報）</p></section>'
                .'<section class="section"><h2>原典の記述</h2><p>'.Security::h($r['description']??'記録なし').'</p>'
                .'<p>技術キー：'.Security::h($r['seed_key']).'</p><p>公開情報や求人募集の確認済み企業としては扱いません。</p></section>'
                .'<a class="text-link" href="/companies#private-research">企業・既存調査資料の一覧へ戻る</a>';
            Kernel::layout((string)$r['display_name'],EntityPageDecor::decorate($db,'companies',$id,$html));return;
        }
        $locs=BusinessRepository::list($db,'company_locations',true,100,0,$id);
        $jobs=BusinessRepository::jobsForCompany($db,$id,true);
        $src=BusinessRepository::sourcesForCompany($db,$id);
        $html='<section class="hero compact"><span class="eyebrow">COMPANY PROFILE</span><h1>'.Security::h($r['display_name']).'</h1><p>'.Security::h($r['description']??'公開情報を確認中です。').'</p><a class="text-link" href="/companies">企業一覧へ ←</a>'.(($srcId=ResearchR07::sourceId($db,'companies',$id))!==null?ResearchR07::compareButton('companies',$srcId):'').'</section>';
        if(!$locationsOnly){
            $html.='<section class="section"><h2>会社情報</h2>'.ResearchR07::pairTable(['法人名'=>$r['legal_name']??null,'会社概要'=>$r['description']??null,'公式サイト'=>$r['website_url']??null]);
            $html.='</section>';
            $externalRows=ResearchExternalLinkRepository::forCompany($db,$id);
            if(ResearchExternalLinks::safeUrl($r['website_url']??null))$externalRows[]=['url'=>$r['website_url'],'link_kind'=>'official','visibility'=>'published'];
            $external=ResearchExternalLinks::buttons($externalRows);
            if($external!=='')$html.='<section class="section"><h2>公式・採用・求人・応募の外部リンク</h2>'.$external.'</section>';
        }
        $html.='<section class="section"><h2>本社・事業所と実際の勤務予定地</h2><p class="note">本社所在地と就業場所は異なる場合があります。求人への紐付けがない拠点は「実勤務地確定」として扱いません。</p>';
        if(!$locs)$html.='<p>拠点の確認済みデータがまだありません。</p>';
        foreach($locs as $loc){$head=$loc['kind']==='headquarters'?'本社':'事業拠点';$html.='<article class="panel"><h3>'.Security::h($loc['name']).'</h3><p>'.Security::h($head).' / '.Security::h($loc['address']??'住所未確認').GoogleMapsLink::button($loc['address']??null).'</p><small>所在地の検証：'.Security::h(BusinessData::verificationLabel((string)$loc['verification_state'])).'</small></article>';}
        $html.='</section>';
        if(!$locationsOnly){
            $html.=ResearchSupplement::html(ResearchSupplementRepository::forCompany($db,$id));
            $html.='<section class="section"><h2>待遇・財務・沿革・口コミ（公開根拠付き）</h2>'.CompanyFacts::html(CompanyFacts::list($db,$id,true)).'</section>';
            $html.='<section class="section"><h2>求人・勤務地の確認状況</h2>';
            if(!$jobs)$html.='<p>掲載確認済みの求人データはありません。</p>';
            foreach($jobs as $job){
                $work=BusinessRepository::locationsForJob($db,$job['id'],true);
                $html.='<article class="panel"><h3><a class="text-link" href="/jobs/'.$job['id'].'">'.Security::h($job['title']).'</a></h3><p>募集状態：'.Security::h(self::recruitmentLabel($job['recruitment_state'])).'（確認日 '.Security::h($job['observed_on']??'未確認').'）</p>';
                $html.='<p>実勤務予定地：';
                if(!$work)$html.='未確認（本社住所で代用しません）';
                else { $tokens=[];foreach($work as $w)$tokens[]=Security::h($w['name']).'（'.Security::h($w['assignment_state']==='confirmed'?'確定':'候補').'）';$html.=implode('、',$tokens); }
                $html.='</p></article>';
            }
            if(Identity::current()!==null)$html.='<p><a href="/contributions/new?type=jobs&amp;action=create&amp;company_id='.$id.'">求人を追加提案 →</a>　<a href="/contributions/new?type=company_locations&amp;action=create&amp;company_id='.$id.'">拠点を追加提案 →</a>　<a href="/contributions/new?type=brands&amp;action=create&amp;company_id='.$id.'">ブランドを追加提案 →</a></p>';
            $html.='</section><p><a class="text-link" href="/companies/'.$id.'/timeline">自分の選考タイムライン →</a></p><p><a class="text-link" href="/companies/'.$id.'/map">確認済み拠点を地図で確認 →</a>　<a class="text-link" href="/companies/'.$id.'/library">企業資料ライブラリ →</a></p><section class="section"><h2>参照資料</h2>';
            if(!$src)$html.='<p>公開可能な出典を確認中です。</p>';
            foreach($src as $s){$html.='<p>'.Security::h($s['title']).'（'.Security::h($s['observed_on']??'日付不明').'）';if(ResearchExternalLinks::safeUrl($s['source_url']??null))$html.=' <a class="btn ghost external-link" rel="noopener noreferrer nofollow" target="_blank" href="'.Security::h($s['source_url']).'">出典ページ</a>';$html.='</p>';}
            $html.='</section>';
        }
        if(!$locationsOnly)$html.='<p><a class="text-link" href="/companies/'.$id.'/activity">公開変更履歴 →</a></p>';
        $me=Identity::current();if($me!==null&&!$locationsOnly)$html.=WorkspaceController::favoriteButton($id,in_array($id,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'companies'),true),'/companies/'.$id,'companies');
        $admin=Identity::current();if(!$locationsOnly&&$admin!==null&&in_array($admin['role'],['ADMIN','FOUNDER','DEVELOPER'],true))$html.='<p><a href="/admin/companies/'.$id.'/facts">出典付き待遇・財務・口コミの管理 →</a></p>';
        if(Identity::current()!==null&&!$locationsOnly){
            $me=(int)Identity::current()['id'];$faves=WorkspaceRepository::favoriteIds($db,$me);
            $html.='<section class="section"><h2>マイリストと個人メモ</h2>'.WorkspaceController::favoriteButton($id,in_array($id,$faves,true),'/companies/'.$id);
            $html.='<form method="post" action="/my/note">'.Session::csrfField().'<input type="hidden" name="company_id" value="'.$id.'"><input type="hidden" name="return_to" value="/companies/'.$id.'"><label>自分用の非公開メモ<textarea name="note" rows="4" maxlength="3000">'.Security::h(WorkspaceRepository::note($db,$me,$id)).'</textarea></label><button class="btn primary">メモを保存（空欄で削除）</button></form></section>';
        }
        if(Identity::current()!==null){$html.='<p><a class="text-link" href="/contributions/new?type=companies&amp;action=update&amp;id='.$id.'">企業情報の修正を提案 →</a>　<a class="text-link" href="/contributions/new?type=companies&amp;action=archive&amp;id='.$id.'">削除の提案 →</a></p>';}
        if(!$locationsOnly && Identity::current()!==null){
            $private=ApplicationRepository::companyTracks($db,(int)Identity::current()['id'],$id);
            $html.='<section class="section"><h2>自分の選考状況</h2><p>記録はあなただけに表示されます。</p>';
            foreach($private as $track)$html.='<div class="panel"><p>'.Security::h($track['job_title']??'企業全体').' / '.Security::h(ApplicationData::LABELS[$track['status']]).'</p>'.ApplicationController::statusForm($id,$track['job_id'],$track).'</div>';
            if($private===[])$html.=ApplicationController::statusForm($id);
            $html.='</section>';
        }
        $html.='<p><a class="text-link" href="/companies/'.$id.($locationsOnly?'':'/locations').'">'.($locationsOnly?'企業概要へ戻る':'拠点のみ表示').' →</a></p>';
        Kernel::layout((string)$r['display_name'],EntityPageDecor::decorate($db,'companies',$id,$html));
    }
    private static function jobs(PDO $db):void {
        $page=self::page();$filters=WorkspaceRepository::normalizeFilters($_GET);
        $all=DirectoryListing::all($db,'jobs',$filters);
        [$page,$last,$rows,$total]=DirectoryListing::pages($all,$page);
        $html='<section class="hero compact"><span class="eyebrow">CAREER OPPORTUNITIES</span><h1>仕事を探す</h1><p>採用条件と公開確認日を区別して表示します。掲載情報は現在の募集受付を保証しません。</p></section>';
        $html.=BusinessSearch::form('jobs',$filters);
        $uid=Identity::current()!==null?(int)Identity::current()['id']:null;
        $favorites=$uid!==null?WorkspaceRepository::favoriteKeys($db,$uid,'jobs'):[];
        $html.='<div class="cards directory-results"><p class="directory-page-info">'.Security::h(DirectoryListing::summary($total,$page)).'</p>';
        foreach($rows as $item){
            $r=$item['row'];$research=$item['origin']==='research';$id=$item['id'];
            $source=$research?$id:ResearchR07::sourceId($db,'jobs',$id);
            $currentRecord=(!$research && $source!==null)?ResearchR07::job($source):null;
            $title=$research?ResearchR07::jobTitle($r):($currentRecord!==null?ResearchR07::jobTitle($currentRecord):(string)$r['title']);
            $co=$research?ResearchR07::company((string)$r['company_id']):($currentRecord!==null?ResearchR07::company((string)($currentRecord['company_id']??'')):null);
            $company=$co!==null?ResearchR07::displayName($co):(string)($r['company_display_name']??'企業未確認');
            $url=$research?ResearchR07::jobUrl($db,$id):'/jobs/'.$id;
            $html.='<article class="panel"><h2><a class="text-link" href="'.Security::h($url).'">'.Security::h($title).'</a></h2><p>企業：'.Security::h($company).'</p>';
            if($research)$html.='<p class="note">研究資料の収録記録です。募集中・応募可能の確定情報ではありません。</p>';
            else $html.=$currentRecord!==null?'<p class="note">公開資料の確認記録です。現在の応募受付と残枠は未確認です。</p>':'<p>'.Security::h(self::recruitmentLabel((string)$r['recruitment_state'])).' ／ 確認日：'.Security::h($r['observed_on']??'未確認').'</p>';
            if($source!==null)$html.=ResearchR07::compareButton('jobs',$source);
            if(!$research){$r06=PrivateR06View::snapshot($db,'jobs',$id);if($r06!==null)$html.=PrivateR06View::jobListMeta($r06);}
            if($uid!==null)$html.=WorkspaceController::favoriteButton($id,in_array($id,$favorites,true),BusinessSearch::paginationUrl('/jobs',$filters,$page),'jobs');
            $html.='</article>';
        }
        if($total===0)$html.='<p class="note">条件に合う求人がありません。</p>';
        $html.='</div>'.DirectoryListing::pagination('/jobs',$filters,$page,$last,$total);
        Kernel::layout('仕事を探す',$html);
    }
    private static function job(PDO $db,string $id):void {
        $r06=PrivateR06View::snapshot($db,'jobs',$id);
        $r=$r06!==null?BusinessRepository::read($db,'jobs',$id,false):BusinessRepository::read($db,'jobs',$id,true);
        $company=$r!==null?($r06!==null?BusinessRepository::read($db,'companies',(string)$r['company_id'],false):BusinessRepository::read($db,'companies',(string)$r['company_id'],true)):null;
        if(!$r||!$company||($company['visibility']??null)==='archived'||($r['archived_at']??null)!==null
            ||($r06!==null && PrivateR06View::snapshot($db,'companies',(string)$r['company_id'])===null)){
            self::message('求人が見つかりません',404);return;
        }
        if($r06!==null){
            $html=PrivateR06View::jobDetail($r,$r06,BusinessRepository::locationsForJob($db,$id,false));
            $me=Identity::current();if($me!==null)$html.=WorkspaceController::favoriteButton($id,in_array($id,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'jobs'),true),'/jobs/'.$id,'jobs');
            $me=Identity::current();
            if($me!==null){
                $saved=EligibilityRepository::checked($db,(int)$me['id'],$id);
                $html.=JobEligibility::render($r,null,$saved,true);
                $tracks=ApplicationRepository::companyTracks($db,(int)$me['id'],(string)$r['company_id']);
                $mine=null;foreach($tracks as $track){if(($track['job_id']??null)===$id){$mine=$track;break;}}
                $html.='<section class="section"><h2>自分の選考状況</h2>'.ApplicationController::statusForm((string)$r['company_id'],$id,$mine).'</section>';
            }
            $liveJob=ResearchR07::job((string)($r06['source_job_id']??''));Kernel::layout($liveJob!==null?ResearchR07::jobTitle($liveJob):(string)$r['title'],EntityPageDecor::decorate($db,'jobs',$id,$html));return;
        }
        $work=BusinessRepository::locationsForJob($db,$id,true);
        $external=ResearchExternalLinks::buttons(ResearchExternalLinkRepository::forJob($db,$r['company_id'],$id),'job');
        $html='<section class="hero compact"><h1>'.Security::h($r['title']).'</h1><p>募集：'.Security::h(self::recruitmentLabel($r['recruitment_state'])).'</p>'.(($sourceJobId=ResearchR07::sourceId($db,'jobs',$id))!==null?ResearchR07::compareButton('jobs',$sourceJobId):'').'</section><p>卒業年度：'.Security::h(PrivateR06View::displayYear($r['graduate_year'])).' / 確認日：'.Security::h($r['observed_on']??'未確認').' / 締切情報：'.Security::h($r['deadline_on']??'未確認').'</p><p>'.Security::h($r['note']??'').'</p><section class="section"><h2>この求人の実勤務地</h2>';
        if(!$work)$html.='<p class="note">未確認です。企業本社の所在地を勤務地として代用しません。</p>';
        foreach($work as $w)$html.='<p>'.Security::h($w['name']).' — '.Security::h($w['address']??'住所未確認').GoogleMapsLink::button($w['address']??null).'（'.Security::h(BusinessData::assignmentLabel((string)$w['assignment_state'])).'）</p>';
        $html.='</section>';
        if($external!=='')$html.='<section class="section"><h2>この求人の外部リンク</h2>'.$external.'</section>';
        $html.=ResearchSupplement::html(ResearchSupplementRepository::forJob($db,$r['company_id'],$id));
        $html.='<a class="text-link" href="/companies/'.$r['company_id'].'">企業詳細へ →</a>';
        $me=Identity::current();if($me!==null)$html.=WorkspaceController::favoriteButton($id,in_array($id,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'jobs'),true),'/jobs/'.$id,'jobs');
        $jobSource=($r['source_id']??null)!==null?BusinessRepository::read($db,'sources',(string)$r['source_id'],true):null;
        $user=Identity::current();$saved=$user!==null?EligibilityRepository::checked($db,(int)$user['id'],$id):[];
        $html.=JobEligibility::render($r,$jobSource,$saved,$user!==null);
        if(Identity::current()!==null){$tracks=ApplicationRepository::companyTracks($db,(int)Identity::current()['id'],$r['company_id']);$mine=null;foreach($tracks as $t){if($t['job_id']===$id){$mine=$t;break;}}$html.='<section class="section"><h2>この求人の選考状況</h2>'.ApplicationController::statusForm($r['company_id'],$id,$mine).'</section>';} 
        if(Identity::current()!==null)$html.='<p><a class="text-link" href="/contributions/new?type=jobs&amp;action=update&amp;id='.$id.'">求人情報の修正を提案 →</a></p>';
        Kernel::layout((string)$r['title'],EntityPageDecor::decorate($db,'jobs',$id,$html));
    }
    private static function sources(PDO $db):void {
        $rows=BusinessRepository::list($db,'sources',true,50,0);
        $html='<section class="hero compact"><h1>公開出典一覧</h1><p>公式情報、求人媒体、SNS、調査スナップショットを区別します。個人資料はここに含めません。</p></section>';
        if(!$rows)$html.='<p>公開可能な出典はまだありません。</p>';
        foreach($rows as $row){$html.='<article class="panel"><h2>'.Security::h($row['title']).'</h2><p>分類：'.Security::h(BusinessData::sourceTypeLabel($row['source_type']??null)).'・確認日：'.Security::h($row['observed_on']??'不明').'</p>';if(ResearchExternalLinks::safeUrl($row['source_url']??null))$html.='<a class="btn ghost external-link" href="'.Security::h($row['source_url']).'" rel="noopener noreferrer nofollow" target="_blank">出典ページ</a>';$html.='</article>';}
        Kernel::layout('公開出典一覧',$html);
    }
    private static function recruitmentLabel(string $v): string {return BusinessData::recruitmentLabel($v);}
    private static function page(): int { $v=$_GET['page']??'1';return is_string($v)&&preg_match('/\A[1-9][0-9]{0,3}\z/D',$v)?min((int)$v,1000):1; }
    private static function filteredPagination(string $path,array $filters,int $page,int $count):string {
        $html='<nav aria-label="ページ切替">';
        if($page>1)$html.='<a class="text-link" href="'.Security::h(BusinessSearch::paginationUrl($path,$filters,$page-1)).'">前へ</a> ';
        if($count===25)$html.='<a class="text-link" href="'.Security::h(BusinessSearch::paginationUrl($path,$filters,$page+1)).'">次へ</a>';
        return $html.'</nav>';
    }
    private static function pagination(string $path,int $page,int $count):string {
        $html='<nav aria-label="ページ切替">';if($page>1)$html.='<a class="text-link" href="'.$path.'?page='.($page-1).'">前へ</a> ';
        if($count===25)$html.='<a class="text-link" href="'.$path.'?page='.($page+1).'">次へ</a>';
        return $html.'</nav>';
    }
    private static function message(string $text,int $status):void {http_response_code($status);Kernel::layout($text,'<section class="hero compact"><h1>'.Security::h($text).'</h1></section>',false);}
}
