<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class WeeklyReportController {
    public const PATH='/admin/messages/weekly-report';
    public static function handle(PDO $db,string $method,array $user):void {
        if(!in_array($user['role']??'', ['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        $c=WeeklyReport::config($db);$error='';$state=WeeklyReport::get($db,'weekly_report.state.v1',null);
        if($method==='POST'){
            if(!is_string($_POST['csrf']??null)||!Session::validCsrf($_POST['csrf'])){http_response_code(403);return;}
            if((int)($_SESSION['authenticated_at']??0)<time()-900){header('Location: /admin/login',true,303);return;}
            try{WeeklyReport::save($db,['enabled'=>($_POST['enabled']??'')==='1','weekday'=>(int)($_POST['weekday']??0),'time'=>$_POST['time']??null,'frequency'=>(int)($_POST['frequency']??0),'subject'=>$_POST['subject']??null,'template'=>$_POST['template']??null],(int)$user['id']);header('Location: '.self::PATH.'?updated=1',true,303);return;}catch(\InvalidArgumentException|\RuntimeException $e){$error='保存できません。入力を確認してください。';http_response_code(422);}
        }elseif($method!=='GET'){http_response_code(405);return;}
        $h='<section class="hero compact"><h1>週報メッセージ設定</h1><p>日本時間で定期配信します。CLIの定期実行設定が必要です。</p></section>'.($error!==''?'<p role="alert">'.Security::h($error).'</p>':'').(isset($_GET['updated'])?'<p role="status">保存しました。</p>':'');
        $h.='<p class="note">次回予定：'.Security::h(isset($state['next_due'])?(new \DateTimeImmutable($state['next_due']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i').'（日本時間）':'未設定').'</p>';
        $h.='<form class="setup-form" method="post">'.Session::csrfField().'<label><input type="checkbox" name="enabled" value="1"'.($c['enabled']?' checked':'').'>定期配信を有効にする</label><label>曜日<select name="weekday">';foreach(['月','火','水','木','金','土','日'] as $i=>$label)$h.='<option value="'.($i+1).'"'.($c['weekday']===$i+1?' selected':'').'>'.$label.'曜日</option>';
        $h.='</select></label><label>配信時刻（日本時間）<input type="time" name="time" required value="'.Security::h($c['time']).'"></label><label>頻度（何週間ごと）<input type="number" min="1" max="12" name="frequency" required value="'.$c['frequency'].'"></label><label>件名<input name="subject" maxlength="65" required value="'.Security::h($c['subject']).'"></label><label>本文テンプレート<textarea name="template" rows="10" maxlength="1500" required>'.Security::h($c['template']).'</textarea></label><p>必須変数：{period}（対象期間）、{counts}（収録件数・増減）、{changes}（一覧と理由・リンク）。</p><button class="btn primary">保存</button></form>';
        Kernel::layout('週報設定',$h,false);
    }
}
