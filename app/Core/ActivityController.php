<?php
declare(strict_types=1);
namespace KMM\Core;

/** Sanitized, separately populated public activity log; no private audit JSON. */
final class ActivityController {
    public static function isRoute(string $path):bool {
        return $path==='/activity' || preg_match('~\A/companies/[a-f0-9]{32}/activity\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){http_response_code(405);Kernel::layout('許可されない操作','<p>読み込み専用です。</p>',false);return;}
        $db=Database::connected();
        $v=$_GET['page']??'1';$page=is_string($v)&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$v)?min((int)$v,1000):1;
        $companyId=null;
        if(preg_match('~\A/companies/([a-f0-9]{32})/activity\z~D',$path,$m)){
            $companyId=$m[1];if(!BusinessRepository::read($db,'companies',$companyId,true)){http_response_code(404);Kernel::layout('企業が見つかりません','<p>公開企業情報を確認できません。</p>',false);return;}
        }
        $rows=ProposalRepository::publishedChanges($db,$page,$companyId);
        $h='<section class="hero compact"><span class="eyebrow">PUBLIC ACTIVITY</span><h1>公開情報の変更履歴</h1><p>公開可能な変更の要約だけを表示します。個人の就活情報、提出理由、内部監査ログは公開しません。</p></section>';
        if(!$rows)$h.='<p class="note">公開できる変更履歴はまだありません。</p>';
        foreach($rows as $row){$h.='<article class="panel"><h2>'.Security::h($row['summary']).'</h2><p><a href="/companies/'.$row['company_id'].'">'.Security::h($row['display_name']).'</a> / '.Security::h($row['created_at']).'</p></article>';}
        $h.='<nav aria-label="ページ切替">'.($page>1?'<a href="?page='.($page-1).'">前へ</a> ':'').(count($rows)===25?'<a href="?page='.($page+1).'">次へ</a>':'').'</nav>';
        $h.='<p><a class="text-link" href="/contributions">自分の修正提案を見る</a></p>';
        Kernel::layout('公開変更履歴',$h);
    }
}
