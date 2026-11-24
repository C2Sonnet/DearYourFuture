<?php
declare(strict_types=1);
namespace KMM\Core;
final class ReminderController {
    public static function isRoute(string $path):bool{return $path==='/applications/reminders';}
    public static function handle(string $method):void {
        $user=Identity::current();
        if(!$user){header('Location: /account/login?next=%2Fapplications%2Freminders',true,302);return;}
        $db=Database::connected();$uid=(int)$user['id'];
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);return;}
            if(!Throttle::allow('reminder-settings:'.$uid,10,900)){http_response_code(429);return;}
            $raw=$_POST['lead_hours']??'';
            if(!is_string($raw)||!preg_match('/\A(?:1|24|48|72)\z/D',$raw)||!in_array((string)($_POST['enabled']??''),['yes','no'],true)){http_response_code(422);Kernel::layout('設定できません','<p>リマインダーの入力を確認してください。</p>');return;}
            try {ReminderRepository::set($db,$uid,$_POST['enabled']==='yes',(int)$raw);}
            catch(\DomainException){http_response_code(409);Kernel::layout('設定できません','<p>アカウントの状態が変わりました。再ログインして確認してください。</p>');return;}
            header('Location: /applications/reminders?saved=1',true,303);return;
        }
        if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');return;}
        $pref=ReminderRepository::preferences($db,$uid);
        $html='<section class="hero compact"><h1>面接・説明会の任意リマインダー</h1><p>通知は本人だけのサイト内メッセージです。初期状態はOFF。時刻未定・終了済み・削除済みの予定は対象外です。</p></section>';
        if(isset($_GET['saved']))$html.='<p role="status">設定を保存しました。</p>';
        $html.='<form class="setup-form" method="post">'.Session::csrfField().'<fieldset><legend>通知を使う</legend><label><input type="radio" name="enabled" value="yes"'.((int)$pref['enabled']===1?' checked':'').'> ON</label><label><input type="radio" name="enabled" value="no"'.((int)$pref['enabled']!==1?' checked':'').'> OFF</label></fieldset><label>予定の何時間前から通知するか<select name="lead_hours">';
        foreach(ReminderRepository::HOURS as $h)$html.='<option value="'.$h.'"'.((int)$pref['lead_hours']===$h?' selected':'').'>'.$h.'時間</option>';
        $html.='</select></label><button class="btn primary">設定を保存</button></form><p class="note">通知配送CLIを運営者が実行した場合にだけメッセージが作成されます。予定ごとに一度だけ送信し、SMTPは利用しません。</p>';
        Kernel::layout('任意リマインダー',$html);
    }
}
