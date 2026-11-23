<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class AdminController {
    public static function handle(string $path,string $method,array $user):void {
        $role=(string)$user['role'];
        if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)){
            http_response_code(403);Kernel::layout('アクセス権がありません','<section class="hero compact"><h1>アクセス権がありません</h1></section>',false);return;
        }
        $db=Database::connected();
        if(FounderPrivacyController::isRoute($path)){FounderPrivacyController::handle($db,$path,$method,$user);return;}
        if(WelcomeSettingsController::isRoute($path)){WelcomeSettingsController::handle($db,$path,$method,$user);return;}
        if($path===LoginNoticeSettingsController::PATH){LoginNoticeSettingsController::handle($db,$method,$user);return;}
        if(AdminInsightsController::isRoute($path)){AdminInsightsController::handle($db,$path,$method);return;}
        if(AdminDevelopmentController::isRoute($path)){AdminDevelopmentController::handle($path,$method);return;}
        if(AdminSeedController::promptIfDue($db,(int)$user['id'],$path,$method))return;
        if($path===AdminExternalLinksController::PATH){AdminExternalLinksController::handle($method,$user);return;}
        if($path===AdminResearchPublicationController::PATH){AdminResearchPublicationController::handle($method,$user);return;}
        if(AdminResearchIdentityController::isRoute($path)){AdminResearchIdentityController::handle($method,$user);return;}
        if(AdminSeedController::isRoute($path)){AdminSeedController::handle($path,$method,$user);return;}
        if(AdminUsersController::isRoute($path)){AdminUsersController::handle($path,$method,$user);return;}
        if(AdminMessagesController::isRoute($path)){AdminMessagesController::handle($path,$method,$user);return;}
        if(SystemSettingsController::isRoute($path)){SystemSettingsController::handle($path,$method,$user);return;}
        if(AdminDiagnosticsController::isRoute($path)){AdminDiagnosticsController::handle($path,$method,$user);return;}
        if(AdminProposalsController::isRoute($path)){AdminProposalsController::handle($path,$method,$user);return;}
        if(AdminFactsController::isRoute($path)){AdminFactsController::handle($path,$method,$user);return;}
        if(BusinessAdminController::isRoute($path)){BusinessAdminController::handle($path,$method,$user);return;}
        if(LibraryAdminController::isRoute($path)){LibraryAdminController::handle($path,$method,$user);return;}
        if($path!=='/admin/site/access-policy'){
            http_response_code(404);
            Kernel::layout('管理画面が見つかりません','<section class="hero compact"><h1>管理画面が見つかりません</h1><p><a href="/admin">管理パネルに戻る</a></p></section>',false);return;
        }
        if(!AccessPolicy::canChangeGuestBrowsing($role)){http_response_code(403);return;}
        $db=Database::connected();$current=Database::settings($db,'access.guest_browsing_enabled');
        if(!is_bool($current)){http_response_code(503);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);Kernel::layout('操作は無効です','<p>CSRF認証に失敗しました。</p>',false);return;}
            if(!in_array((string)($_POST['guest_browsing']??''),['on','off'],true)||(string)($_POST['confirmed']??'')!=='yes'){
                http_response_code(422);Kernel::layout('入力内容を確認','<p>公開範囲を確認してから変更してください。</p>',false);return;
            }
            $next=$_POST['guest_browsing']==='on';
            if($next!==$current){
                $db->beginTransaction();
                try {
                    Database::setSetting($db,'access.guest_browsing_enabled',$next);
                    $stmt=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)');
                    $stmt->execute([$user['id'],'site.access_policy.changed','setting','access.guest_browsing_enabled',json_encode(['before'=>$current,'after'=>$next],JSON_THROW_ON_ERROR)]);
                    $db->commit();
                }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            }
            header('Location: /admin/site/access-policy?updated=1',true,303);return;
        }
        if($method!=='GET'){header('Allow: GET, POST');http_response_code(405);return;}
        $checked=$current?' checked':'';
        $html='<section class="hero compact"><span class="eyebrow">ADMIN · SITE ACCESS</span><h1>未ログイン閲覧の設定</h1><p>共通パスワードとユーザーログインは別の保護機能です。</p></section>';
        if(isset($_GET['updated']))$html.='<p role="status" class="note">公開設定を保存しました。</p>';
        $html.='<form method="post" class="setup-form" action="/admin/site/access-policy">'.Session::csrfField().'<fieldset><legend>未ログイン閲覧</legend><label><input type="radio" name="guest_browsing" value="off"'.(!$current?' checked':'').'> OFF（ログインを要求する）</label><label><input type="radio" name="guest_browsing" value="on"'.$checked.'> ON（許可済みの公開ページだけ）</label></fieldset><label><input type="checkbox" name="confirmed" value="yes" required> 個人の応募状況、管理機能、非公開資料は常にログイン必須であることを確認しました。</label><button type="submit" class="btn primary">設定を保存する</button></form>';
        Kernel::layout('公開設定',$html,false);
    }
}
