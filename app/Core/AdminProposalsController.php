<?php
declare(strict_types=1);
namespace KMM\Core;

/** Reviewer-only inbox: no browser-side auto-approval, optimistic-lock checks live in repository. */
final class AdminProposalsController {
    public static function isRoute(string $path):bool {
        return $path==='/admin/proposals'||preg_match('~\A/admin/proposals/[a-f0-9]{32}\z~D',$path)===1;
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array((string)$user['role'],['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        $db=Database::connected();
        if($path==='/admin/proposals'){
            if($method!=='GET'){self::problem('許可されない操作',405);return;}
            $v=$_GET['page']??'1';$page=is_string($v)&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$v)?min((int)$v,1000):1;
            $rows=ProposalRepository::listReview($db,$page);
            $h='<section class="hero compact"><h1>企業情報の修正提案を審査</h1><p>提出した本人の情報は別途監査管理します。審査内容は自動公開されません。</p></section>';
            if(!$rows)$h.='<p class="note">審査待ちの提案はありません。</p>';
            foreach($rows as $r)$h.='<article class="panel"><h2><a class="text-link" href="/admin/proposals/'.$r['id'].'">'.Security::h(ProposalData::publicSummary($r['record_type'],$r['operation'])).'</a></h2><p>'.Security::h($r['created_at']).'</p><p>'.Security::h($r['reason']).'</p></article>';
            $h.='<nav aria-label="ページ送り">'.($page>1?'<a href="?page='.($page-1).'">前へ</a> ':'').(count($rows)===20?'<a href="?page='.($page+1).'">次へ</a>':'').'</nav>';
            Kernel::layout('提案審査',$h,false);return;
        }
        $id=substr($path,strlen('/admin/proposals/'));
        $row=ProposalRepository::readReview($db,$id);
        if(!$row){self::problem('提案が見つかりません',404);return;}
        if($method==='POST'){
            $csrf=$_POST['csrf']??null;
            if(!is_string($csrf)||!Session::validCsrf($csrf)){self::problem('操作が認証されませんでした',403);return;}
            $decision=$_POST['decision']??null;$note=$_POST['note']??null;
            if(!is_string($decision)||!is_string($note)){self::problem('審査入力が不正です',422);return;}
            try {
                ProposalRepository::decide($db,$id,(int)$user['id'],(string)$user['role'],$decision,$note);
                header('Location: /admin/proposals?reviewed=1',true,303);return;
            }catch(\InvalidArgumentException $e){self::problem($e->getMessage(),422);return;}
            catch(\DomainException $e){self::problem('審査権限を確認してください',403);return;}
            catch(\RuntimeException $e){self::problem($e->getMessage(),409);return;}
        }
        if($method!=='GET'){self::problem('許可されない操作',405);return;}
        $h='<section class="hero compact"><h1>修正提案を確認</h1><p>内容と出典、既存企業情報の正確性を確認してから操作してください。</p></section>';
        $h.='<p>種類：'.Security::h($row['record_type']).' / 処理：'.Security::h($row['operation']).' / 状態：'.Security::h($row['status']).'</p>';
        $h.='<p>理由：'.Security::h($row['reason']).'</p><p>根拠：';
        $h.=$row['evidence_url']?'<a rel="noopener noreferrer nofollow" target="_blank" href="'.Security::h($row['evidence_url']).'">参照URL</a>':'未指定';
        $h.='</p>';
        if($row['record_id'])$h.='<p>対象レコード：'.Security::h($row['record_id']).' / 提出時revision '.(int)$row['base_revision'].'</p>';
        if($row['company_id'])$h.='<p>会社：<a href="/admin/data/companies/'.$row['company_id'].'">管理画面で確認</a></p>';
        if($row['proposed_json']){$values=json_decode($row['proposed_json'],true);if(is_array($values)){$h.='<h2>提案内容</h2><dl>';foreach($values as $key=>$value){$h.='<dt>'.Security::h((string)$key).'</dt><dd>'.Security::h($value===null?'（空欄）':(string)$value).'</dd>';}$h.='</dl>';}}
        if($row['status']==='pending'){
            $h.='<form method="post" action="/admin/proposals/'.$id.'" class="setup-form">'.Session::csrfField();
            $h.='<fieldset><legend>審査結果</legend><label><input type="radio" name="decision" value="approve" required> 承認・反映</label><label><input type="radio" name="decision" value="needs_revision"> 追加確認を依頼</label><label><input type="radio" name="decision" value="reject"> 見送り</label></fieldset>';
            $h.='<label>審査理由（必須）<textarea name="note" maxlength="1500" rows="4" required></textarea></label><button class="btn primary" type="submit">審査結果を確定</button></form>';
        }else $h.='<p>この提案は処理済みです。</p>';
        $h.='<a class="text-link" href="/admin/proposals">審査一覧へ</a>';
        Kernel::layout('提案審査',$h,false);
    }
    private static function problem(string $message,int $code):void {http_response_code($code);Kernel::layout('審査できません','<section class="hero compact"><h1>'.Security::h($message).'</h1></section>',false);}
}
