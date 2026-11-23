<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class AdminFactsController {
    public static function isRoute(string $path):bool{return preg_match('~\A/admin/companies/[a-f0-9]{32}/facts\z~D',$path)===1;}
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array($user['role']??null,['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        $id=substr($path,strlen('/admin/companies/'),32);$db=Database::connected();
        $company=BusinessRepository::read($db,'companies',$id,false);
        if(!$company){http_response_code(404);Kernel::layout('企業がありません','<h1>企業がありません</h1>',false);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);return;}
            if(!Throttle::allow('fact-write:'.$user['id'],20,900)){http_response_code(429);return;}
            try{
                if(($_POST['action']??'')==='create')CompanyFacts::create($db,$id,(int)$user['id'],$_POST);
                elseif(($_POST['action']??'')==='archive'&&($_POST['confirmed']??'')==='yes')CompanyFacts::archive($db,$id,BusinessData::id((string)($_POST['fact_id']??'')),(int)($_POST['revision']??0),(int)$user['id']);
                else throw new \InvalidArgumentException('Invalid operation');
                header('Location: '.$path,true,303);return;
            }catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('入力を確認','<p>出典と入力内容を確認してください。</p>',false);return;}
            catch(\RuntimeException){http_response_code(409);Kernel::layout('更新競合','<p>再読込して操作してください。</p>',false);return;}
        }
        if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');return;}
        $rows=CompanyFacts::list($db,$id,false);
        $html='<section class="hero compact"><h1>企業の待遇・財務・口コミ記録</h1><p>'.Security::h($company['display_name']).'：値・対象時点・公開根拠を必ずセットで登録します。</p></section>';
        $html.='<form method="post" class="setup-form" action="'.$path.'">'.Session::csrfField().'<input type="hidden" name="action" value="create"><label>情報の種類<select name="topic">';
        foreach(CompanyFacts::LABELS as $k=>$label)$html.='<option value="'.$k.'">'.Security::h($label).'</option>';
        $html.='</select></label><label>項目<input name="label" maxlength="150" required></label><label>値・説明<textarea name="claim_text" maxlength="1200" required></textarea></label><label>根拠資料ID（この企業に関連付け済み）<input name="source_id" maxlength="32" minlength="32" required></label><label>対象時点<input type="date" name="context_on"></label><label>公開状態<select name="publication_state"><option value="private">管理者専用</option><option value="public">根拠確認済みを公開</option></select></label><button class="btn primary">情報を登録する</button></form>';
        $html.='<section class="section"><h2>現在の記録</h2>';
        foreach($rows as $item){$html.='<article class="panel"><h3>'.Security::h($item['label']).'</h3><p>'.Security::h($item['claim_text']).'／'.Security::h($item['topic']).'／'.Security::h($item['publication_state']).'</p><p>出典：'.Security::h($item['source_title']).'</p><form method="post" action="'.$path.'">'.Session::csrfField().'<input type="hidden" name="action" value="archive"><input type="hidden" name="fact_id" value="'.Security::h($item['id']).'"><input type="hidden" name="revision" value="'.(int)$item['revision'].'"><label><input type="checkbox" name="confirmed" value="yes" required>アーカイブを確認</label><button class="btn ghost">アーカイブ</button></form></article>';}
        $html.='</section>';Kernel::layout('企業情報の根拠管理',$html,false);
    }
}
