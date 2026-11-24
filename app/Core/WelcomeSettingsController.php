<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class WelcomeSettingsController {
    public static function isRoute(string $p):bool{return $p==='/admin/messages/welcome';}
    public static function handle(PDO $db,string $path,string $method,array $actor):void {
        if(!in_array((string)($actor['role']??''),['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))||(int)($_SESSION['authenticated_at']??0)<time()-900){http_response_code(403);return;}
            try{$v=WelcomeMessage::validate((string)($_POST['subject']??''),(string)($_POST['body']??''));}
            catch(\Throwable){http_response_code(422);Kernel::layout('入力を確認','<p>件名は80文字、本文は1000文字までです。</p>');return;}
            $db->beginTransaction();
            try{
                $save=$db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES (?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
                $save->execute([WelcomeMessage::SETTING,json_encode($v,JSON_THROW_ON_ERROR)]);
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([(int)$actor['id'],'message.welcome_template.updated','setting',WelcomeMessage::SETTING,json_encode(['subject'=>$v['subject'],'body_sha256'=>hash('sha256',$v['body'])],JSON_THROW_ON_ERROR)]);
                $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            header('Location: /admin/messages/welcome?saved=1',true,303);return;
        }
        if($method!=='GET'){header('Allow: GET, POST');http_response_code(405);return;}
        $v=WelcomeMessage::template($db);
        $html='<section class="hero compact"><h1>MinaHelper・歓迎メッセージ</h1><p>新規登録確定時にサイト内メッセージを一度だけ送ります。返信不可です。変数 {name} は表示名に置き換えられます。</p></section>';
        if(isset($_GET['saved']))$html.='<p role="status">テンプレートを保存しました。既送信メッセージは変更されません。</p>';
        $html.='<form class="setup-form" method="post">'.Session::csrfField().'<label>件名<input name="subject" maxlength="80" required value="'.Security::h($v['subject']).'"></label><label>本文<textarea name="body" maxlength="1000" rows="13" required>'.Security::h($v['body']).'</textarea></label><button class="btn primary">保存する</button></form>';
        Kernel::layout('歓迎メッセージ設定',$html);
    }
}
