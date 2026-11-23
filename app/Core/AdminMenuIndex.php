<?php
declare(strict_types=1);
namespace KMM\Core;
/** Consistent, permission-restricted sidebar for administrative pages. */
final class AdminMenuIndex {
    private const GROUPS=[
      'ダッシュボード'=>[['/admin','管理ホーム'],['/admin/data/statistics','データ集計'],['/admin/development','開発資料']],
      'アカウント・メッセージ'=>[['/admin/users','ユーザー管理'],['/admin/users/new','ユーザー新規作成'],['/admin/messages','メッセージ管理'],['/admin/messages/broadcasts','配信履歴'],['/admin/messages/welcome','歓迎メッセージ']],
      '創始者専用'=>[['/admin/founder/kimi','「キミへ」の閲覧設定'],['/admin/founder/activity','ユーザーの就職活動情報']],
      '企業・求人・資料'=>[['/admin/data/companies','企業管理'],['/admin/data/jobs','求人管理'],['/admin/library','企業資料管理'],['/admin/data/research-identities','企業同定・確認']],
      '審査・研究'=>[['/admin/proposals','修正提案'],['/admin/data/research-publication','研究資料の公開管理'],['/admin/data/conflicts','初期データ管理']],
      'サイト・システム'=>[['/admin/site/countdown','カウントダウン'],['/admin/site/gate','サイト保護'],['/admin/system/mail','SMTP・メール'],['/admin/system/login-notices','休息案内の時刻設定'],['/admin/system/maps','Google Maps'],['/admin/site/access-policy','閲覧権限']]
    ];
    public static function render(string $path,array $user):string {
        if(!in_array((string)($user['role']??''),['ADMIN','FOUNDER','DEVELOPER'],true))return '';
        $html='<aside class="admin-drawer is-collapsed" id="admin-drawer"><button type="button" class="admin-drawer-handle" aria-expanded="false" aria-controls="admin-drawer-content" aria-label="管理目次を展開する">›</button><div id="admin-drawer-content" class="admin-drawer-inner"><h2>管理ページ目次</h2><p>機能から管理ページを探す</p>';
        foreach(self::GROUPS as $label=>$items){if($label==='創始者専用' && ($user['role']??null)!=='FOUNDER')continue;$html.='<details><summary>'.Security::h($label).'</summary><nav aria-label="'.Security::h($label).'">';
            foreach($items as $item){[$url,$name]=$item;$html.='<a href="'.Security::h($url).'"'.($path===$url?' aria-current="page"':'').'>'.Security::h($name).'</a>';}
            $html.='</nav></details>';
        }
        return $html.'</div></aside><script src="/assets/admin-drawer.js" defer></script><script src="/assets/admin-list-pages.js" defer></script>';
    }
}
