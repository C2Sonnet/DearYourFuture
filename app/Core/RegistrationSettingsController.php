<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Developer-authorized opt-in for public self registration; disabled by default. */
final class RegistrationSettingsController {
    public const PATH='/admin/system/registration';
    public static function handle(PDO $db,string $method,array $actor):void {
        if(($actor['role']??'')!=='DEVELOPER'){http_response_code(403);Kernel::layout('アクセス不可','<p>この設定は開発者専用です。</p>');return;}
        $current=RegistrationController::enabled($db);
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))||(int)($_SESSION['authenticated_at']??0)<time()-900){http_response_code(403);Kernel::layout('再認証が必要','<p>再度ログインしてください。</p>');return;}
            if(!in_array((string)($_POST['setting']??''),['enabled','disabled'],true)||(string)($_POST['confirmed']??'')!=='yes'){http_response_code(422);return;}
            $enabled=$_POST['setting']==='enabled';
            $db->beginTransaction();
            try{
                $db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')
                    ->execute([RegistrationController::SETTING,json_encode($enabled,JSON_THROW_ON_ERROR)]);
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([(int)$actor['id'],'account.registration_policy.changed','setting',RegistrationController::SETTING,json_encode(['from'=>$current,'to'=>$enabled],JSON_THROW_ON_ERROR)]);
                $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            header('Location: '.self::PATH.'?saved=1',true,303);return;
        }
        if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');return;}
        $html='<section class="hero compact"><h1>一般ユーザー登録</h1><p>自己登録は初期状態で無効です。許可する場合、新規登録者はUSERとして作成されます。</p></section>';
        if(isset($_GET['saved']))$html.='<p role="status">設定を保存しました。</p>';
        $html.='<form class="setup-form" method="post">'.Session::csrfField()
             .'<label><input type="radio" name="setting" value="disabled"'.(!$current?' checked':'').'>登録を停止</label>'
             .'<label><input type="radio" name="setting" value="enabled"'.($current?' checked':'').'>登録を許可</label>'
             .'<label><input type="checkbox" name="confirmed" value="yes" required>スパム対策・本人確認の運用方針を確認しました。</label><button class="btn primary">変更を保存</button></form>';
        Kernel::layout('ユーザー登録設定',$html);
    }
}
