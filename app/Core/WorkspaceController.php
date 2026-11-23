<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Personal workspace: no cross-account read, and no mutations via GET. */
final class WorkspaceController {
    public static function isRoute(string $path):bool {return in_array($path,['/my/favorite','/my/note','/my/filters','/my/favorites'],true);}
    public static function handle(string $path,string $method):void {
        $user=Identity::current();
        if($user===null){header('Location: /account/login?next='.rawurlencode('/my'),true,302);return;}
        if($path==='/my/favorites' && $method==='GET'){self::favoritePage(Database::connected(),(int)$user['id']);return;}
        if($method!=='POST'){http_response_code(405);header('Allow: POST');Kernel::layout('操作できません','<p>フォームから操作してください。</p>');return;}
        if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);Kernel::layout('確認が必要です','<p>CSRF確認に失敗しました。</p>');return;}
        $uid=(int)$user['id'];
        if(!Throttle::allow('workspace-write:'.$uid,40,900)){http_response_code(429);Kernel::layout('操作制限','<p>少し時間をおいてください。</p>');return;}
        try {
            $db=Database::connected();
            if($path==='/my/favorite'){
                if(!in_array(($_POST['action']??''),['add','remove'],true))throw new \InvalidArgumentException('Unknown favorite operation');
                $kind=(string)($_POST['kind']??'companies');$id=(string)($_POST['item_id']??$_POST['company_id']??'');
                WorkspaceRepository::setFavoriteEntity($db,$uid,$kind,$id,($_POST['action']==='add'));
                if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest'){
                    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
                    echo json_encode(['ok'=>true,'favorite'=>$_POST['action']==='add'],JSON_THROW_ON_ERROR);return;
                }
            }
            elseif($path==='/my/note')WorkspaceRepository::setNote($db,$uid,BusinessData::id((string)($_POST['company_id']??'')),$_POST['note']??'');
            elseif($path==='/my/filters'){
                if(($_POST['action']??'')==='remove')WorkspaceRepository::removeFilter($db,$uid,BusinessData::id((string)($_POST['filter_id']??'')));
                elseif(($_POST['action']??'')==='save')WorkspaceRepository::saveFilter($db,$uid,$_POST['label']??'',$_POST);
                else throw new \InvalidArgumentException('Unknown filter operation');
            }
            header('Location: '.Session::safeReturn((string)($_POST['return_to']??'/my')),true,303);
        }catch(\InvalidArgumentException $e){
            http_response_code(422);
            if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);return;}
            Kernel::layout('入力をご確認ください','<p>'.Security::h($e->getMessage()).'</p>');
        }
    }
    public static function favoriteButton(string $id,bool $present,string $returnTo='/my/favorites',string $kind='companies'):string {
        $action=$present?'remove':'add';$label=$present?'★ お気に入り':'☆ お気に入りに登録';
        return '<form method="post" action="/my/favorite" class="workspace-inline favorite-form">'.Session::csrfField()
            .'<input type="hidden" name="kind" value="'.Security::h($kind).'">'
            .'<input type="hidden" name="item_id" value="'.Security::h($id).'">'
            .'<input type="hidden" name="action" value="'.$action.'">'
            .'<input type="hidden" name="return_to" value="'.Security::h($returnTo).'">'
            .'<button class="btn ghost favorite-toggle" type="submit" aria-pressed="'.($present?'true':'false').'">'.$label.'</button></form>';
    }
    public static function favoritePage(PDO $db,int $uid):void {
        $collections=WorkspaceRepository::favoriteStored($db,$uid);
        $collections['companies']=array_values(array_unique(array_merge($collections['companies'],WorkspaceRepository::favoriteIds($db,$uid))));
        $html='<section class="hero compact"><h1>お気に入り</h1><p>あなたが保存した企業・求人を分けて確認できます。別のアカウントには表示されません。</p></section><div class="favorite-groups">';
        foreach(['companies'=>'お気に入り企業','jobs'=>'お気に入り仕事'] as $kind=>$label){
            $html.='<section class="section"><h2>'.Security::h($label).'（'.count($collections[$kind]).'件）</h2>';
            $html.='<div class="compact-card-grid favorite-card-grid">';
            if($collections[$kind]===[])$html.='<p><a href="'.($kind==='companies'?'/companies':'/jobs').'">'.($kind==='companies'?'企業':'仕事').'を探す</a></p>';
            foreach($collections[$kind] as $id){
                if(ResearchR07::isCompanyId($id)||ResearchR07::isJobId($id)){
                    $r=$kind==='companies'?ResearchR07::company($id):ResearchR07::job($id);
                    $name=$r!==null?($kind==='companies'?ResearchR07::displayName($r):ResearchR07::jobTitle($r)):'現在参照できない収録情報';
                    $url=$kind==='companies'?ResearchR07::companyUrl($db,$id):ResearchR07::jobUrl($db,$id);
                }else{
                    $r=BusinessRepository::read($db,$kind,$id,false);
                    $name=$r!==null?(string)($r[$kind==='companies'?'display_name':'title']??'収録情報'):'現在参照できない収録情報';
                    $url='/'.$kind.'/'.$id;
                }
                $html.='<article class="panel"><h3>'.Security::h($name).'</h3>';
                if($r!==null)$html.='<a class="btn ghost" href="'.Security::h($url).'">詳細を見る</a>';
                $html.=self::favoriteButton($id,true,'/my/favorites',$kind).'</article>';
            }
            $html.='</div></section>';
        }
        Kernel::layout('お気に入り',$html.'</div>');
    }
    public static function panel(PDO $db,int $uid):string {
        $favorites=WorkspaceRepository::favorites($db,$uid);$notes=WorkspaceRepository::notes($db,$uid);$filters=WorkspaceRepository::filters($db,$uid);
        $h='<section class="section my-list-section"><h2>お気に入り企業</h2><p class="note">あなた専用です。公開が取り消された会社はリンクせず、登録履歴だけ保持します。</p>';
        if(!$favorites)$h.='<p>まだ登録はありません。 <a href="/companies">企業を探す</a></p>';
        else foreach($favorites as $r){$public=$r['visibility']==='published';$h.='<article class="panel"><h3>'.Security::h($public?($r['display_name']??'企業'):'現在非公開の企業').'</h3>';
            if($public){$h.='<p><a href="/companies/'.Security::h($r['company_id']).'">企業情報を確認する</a></p>';
                $h.=ResearchExternalLinks::buttons(ResearchExternalLinkRepository::forCompany($db,(string)$r['company_id']),'favorite');}
            $h.=self::favoriteButton($r['company_id'],true,'/my').'</article>';
        }
        if($favorites){
            $sourceIds=[];foreach($favorites as $entry){
                $sourceId=ResearchR07::sourceId($db,'companies',(string)$entry['company_id']);
                if($sourceId!==null)$sourceIds[$sourceId]=true;
            }
            if($sourceIds)$h.='<p><a class="text-link" href="/compare/companies?ids='.rawurlencode(implode(',',array_slice(array_keys($sourceIds),0,10))).'">お気に入り企業を比較（最大10社） →</a></p>';
        }
        $h.='</section><section class="section"><h2>企業メモ（非公開）</h2>';
        if(!$notes)$h.='<p>企業詳細から自分用のメモを作成できます。</p>';
        foreach($notes as $r){$public=$r['visibility']==='published';$h.='<article class="panel"><h3>'.Security::h($public?($r['display_name']??'企業'):'非公開になった企業').'</h3><p>'.Security::h($r['note']).'</p>';
            $h.='<form action="/my/note" method="post">'.Session::csrfField().'<input type="hidden" name="company_id" value="'.Security::h($r['company_id']).'"><input type="hidden" name="return_to" value="/my"><textarea name="note" rows="3" maxlength="3000">'.Security::h($r['note']).'</textarea><button class="btn ghost">メモ更新（空欄で削除）</button></form></article>';
        }
        $h.='</section><section class="section"><h2>保存した検索条件</h2>';
        if(!$filters)$h.='<p>企業検索画面の条件をここへ保存できます。</p>';
        foreach($filters as $r){$data=json_decode($r['filters_json'],true);if(!is_array($data))continue;
            $data=WorkspaceRepository::normalizeFilters($data);
            $h.='<article class="panel"><strong>'.Security::h($r['label']).'</strong> <a href="/companies?'.Security::h(http_build_query($data)).'">検索を開く</a>';
            $h.='<form method="post" action="/my/filters">'.Session::csrfField().'<input type="hidden" name="action" value="remove"><input type="hidden" name="filter_id" value="'.Security::h($r['id']).'"><button class="btn ghost">削除</button></form></article>';
        }
        $h.='</section><p><a class="text-link" href="/guide">ガイドの達成状況を確認する →</a></p>';
        return $h;
    }
}
