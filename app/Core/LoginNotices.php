<?php
declare(strict_types=1);
namespace KMM\Core;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

/** Account-local notices. Server calendar, never the browser clock. */
final class LoginNotices {
    public const KEY = 'account.night_notice_window_v1';
    public const DEFAULT_START = '00:05';
    public const DEFAULT_END = '06:05';
    private const EVENT = 'account.night_notice_v1';

    /** The requesting client address only, never an untrusted forwarded header. */
    public static function clientIp(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '確認できません';
    }

    public static function lastLogin(PDO $db,int $userId): ?array {
        $q=$db->prepare("SELECT created_at,details_json FROM audit_logs WHERE actor_id=? AND event_type='account.login' ORDER BY id DESC LIMIT 1");
        $q->execute([$userId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $details=json_decode((string)$row['details_json'],true);
        if(!is_array($details))$details=[];
        $when=$details['server_at']??$row['created_at'];
        $ip=$details['ip']??null;
        return ['at'=>is_string($when)?$when:'記録なし', 'ip'=>is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)?$ip:'記録なし'];
    }

    public static function flashLogin(?array $last,string $ip):void {
        $_SESSION['kmm_login_notice_flash_v1']=[
            'at'=>$last['at']??'初めてのログイン',
            'previous_ip'=>$last['ip']??'記録なし',
            'current_ip'=>$ip
        ];
    }

    public static function window(PDO $db):array {
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');
        $q->execute([self::KEY]);
        $raw=$q->fetchColumn();
        $value=is_string($raw)?json_decode($raw,true):null;
        $from=is_array($value)?($value['start']??null):null;
        $to=is_array($value)?($value['end']??null):null;
        if(!is_string($from)||!is_string($to)||!self::validTime($from)||!self::validTime($to)||$from===$to){
            return ['start'=>self::DEFAULT_START,'end'=>self::DEFAULT_END];
        }
        return ['start'=>$from,'end'=>$to];
    }
    public static function validTime(string $time):bool {
        return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$time)===1;
    }
    public static function inWindow(string $clock,string $from,string $to):bool {
        if(!self::validTime($clock)||!self::validTime($from)||!self::validTime($to)||$from===$to)return false;
        return $from<$to ? ($clock>=$from && $clock<$to) : ($clock>=$from || $clock<$to);
    }

    /** Runs at most once per server calendar date across browsers and sessions. */
    private static function markNightShown(PDO $db,int $userId,string $serverDay):bool {
        if(($GLOBALS['__kmm_no_notice_write']??false)===true)return false;
        $db->beginTransaction();
        try {
            $guard=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $guard->execute([$userId]);
            if(!$guard->fetchColumn())throw new \RuntimeException('User missing');
            $exists=$db->prepare('SELECT 1 FROM audit_logs WHERE actor_id=? AND event_type=? AND target_type=? AND target_key=? LIMIT 1');
            $exists->execute([$userId,self::EVENT,'user',$serverDay]);
            if($exists->fetchColumn()){$db->commit();return false;}
            $insert=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)');
            $insert->execute([$userId,self::EVENT,'user',$serverDay,'{}']);
            $db->commit();return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function render(PDO $db,array $user):string {
        $userId=(int)($user['id']??0);
        if($userId<1)return '';
        $messages=[];
        $welcome=$_SESSION['kmm_login_notice_flash_v1']??null;
        if(is_array($welcome)){
            unset($_SESSION['kmm_login_notice_flash_v1']);
            $name=Security::h((string)($user['display_name']??''));
            $messages[]='<strong>お帰りなさい、'.$name.'さん</strong><p>前回ログイン：'.Security::h((string)($welcome['at']??'記録なし')).'</p><p>前回IP：<code>'.Security::h((string)($welcome['previous_ip']??'記録なし')).'</code></p><p>現在のIP：<code>'.Security::h((string)($welcome['current_ip']??'確認できません')).'</code></p>';
        }
        $now=new DateTimeImmutable('now',new DateTimeZone(date_default_timezone_get()));
        $day=$now->format('Y-m-d');
        if(($_SESSION['kmm_night_notice_day_v1']??null)!==$day){
            $window=self::window($db);
            if(self::inWindow($now->format('H:i'),$window['start'],$window['end'])&&self::markNightShown($db,$userId,$day)){
                $messages[]='<strong>夜遅くまで、お疲れさまです。</strong><p>深夜の時間です。体を大切に、無理をしすぎず、休息もとってくださいね。</p>';
            }
            // If not yet within the notice window, do not suppress notices later today.
            if(self::inWindow($now->format('H:i'),$window['start'],$window['end']))$_SESSION['kmm_night_notice_day_v1']=$day;
        }
        if(!$messages)return '';
        $html='<aside class="login-notices" aria-label="ログインと休息の案内">';
        foreach($messages as $message){
            $html.='<section class="login-notice" role="status">'.$message.'<button type="button" class="login-notice-dismiss" aria-label="この案内を閉じる" data-login-notice-close>×</button></section>';
        }
        return $html.'</aside>';
    }
}
