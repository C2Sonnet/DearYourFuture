<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Authorized settings for server-time late-night reminders. */
final class LoginNoticeSettingsController {
    public const PATH='/admin/system/login-notices';
    public static function handle(PDO $db,string $method,array $actor):void {
        if(!in_array((string)($actor['role']??''),['ADMIN','FOUNDER','DEVELOPER'],true)){
            http_response_code(403);return;
        }
        $error='';
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))||(int)($_SESSION['authenticated_at']??0)<time()-900){http_response_code(403);return;}
            $start=(string)($_POST['start']??'');$end=(string)($_POST['end']??'');
            if(!LoginNotices::validTime($start)||!LoginNotices::validTime($end)||$start===$end){http_response_code(422);$error='時刻はHH:MM形式で、開始と終了を異なる値にしてください。';}
            else {
                $db->beginTransaction();
                try{
                    $old=LoginNotices::window($db);
                    $q=$db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
                    $q->execute([LoginNotices::KEY,json_encode(['start'=>$start,'end'=>$end],JSON_THROW_ON_ERROR)]);
                    $q=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)');
                    $q->execute([(int)$actor['id'],'account.night_notice_settings.updated','setting',LoginNotices::KEY,json_encode(['from'=>$old,'to'=>['start'=>$start,'end'=>$end]],JSON_THROW_ON_ERROR)]);
                    $db->commit();
                }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
                header('Location: '.self::PATH.'?saved=1',true,303);return;
            }
        }elseif($method!=='GET'){header('Allow: GET, POST');http_response_code(405);return;}
        $times=LoginNotices::window($db);
        $html='<section class="hero compact"><h1>深夜の休息案内</h1><p>サーバー時刻の指定範囲で、ログイン済みの各ユーザーに1日1回、右下へ休息の案内を表示します。</p><p>サーバーのタイムゾーン：'.Security::h(date_default_timezone_get()).'</p></section>';
        if(isset($_GET['saved']))$html.='<p role="status">設定を更新しました。</p>';
        if($error!=='')$html.='<p role="alert">'.Security::h($error).'</p>';
        $html.='<form class="setup-form" method="post" action="'.self::PATH.'">'.Session::csrfField()
            .'<label>開始時刻<input type="time" name="start" value="'.Security::h($times['start']).'" required></label>'
            .'<label>終了時刻<input type="time" name="end" value="'.Security::h($times['end']).'" required></label>'
            .'<p>終了時刻は範囲に含まれません。日付をまたぐ設定にも対応します。</p><button class="btn primary" type="submit">設定を保存</button></form>';
        Kernel::layout('休息案内の設定',$html);
    }
}
