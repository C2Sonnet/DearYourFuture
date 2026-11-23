<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** User-scoped, opt-out aware plain-text notification templates.
 * The existing system_settings table stores settings (no destructive schema migration).
 * Security/verification tokens are intentionally outside these optional templates.
 */
final class MailNotifications {
    public const DEFAULTS = [
        'login' => [
            'name'=>'ユーザーログインのお知らせ',
            'subject'=>'【キミの未来へ】ログインのお知らせ',
            'body'=>"{{user_name}} さん\n\n{{site_name}} にログインがありました。\n日時（日本時間）：{{event_time}}\n\n心当たりがない場合は、アカウントのセキュリティ設定をご確認ください。\n{{url}}\n",
        ],
        'research_updated'=>[
            'name'=>'企業・求人情報の変更のお知らせ',
            'subject'=>'【キミの未来へ】企業・求人情報が更新されました',
            'body'=>"{{user_name}} さん\n\n{{site_name}} で情報が更新されました。\n{{detail}}\n\n確認はこちら：{{url}}\n",
        ],
        'research_added'=>[
            'name'=>'企業・求人情報の追加のお知らせ',
            'subject'=>'【キミの未来へ】新しい企業・求人情報のお知らせ',
            'body'=>"{{user_name}} さん\n\n{{site_name}} に新しい情報が追加されました。\n{{detail}}\n\n確認はこちら：{{url}}\n",
        ],
        'new_message'=>[
            'name'=>'新しいメッセージのお知らせ',
            'subject'=>'【キミの未来へ】新しいメッセージがあります',
            'body'=>"{{user_name}} さん\n\n{{site_name}} に新しいメッセージが届きました。\n本文はメールには掲載していません。\n\n受信箱：{{url}}\n",
        ],
    ];
    private const FIELDS=['site_name','user_name','event_time','detail','url'];
    private static function prefKey(int $userId): string {return 'mail.preference.user.'.(string)$userId;}
    private static function tplKey(string $id): string {return 'mail.template.v1.'.$id;}
    public static function template(PDO $db,string $id):array {
        if(!isset(self::DEFAULTS[$id]))throw new \InvalidArgumentException('Unknown notification type');
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? LIMIT 1');
        $q->execute([self::tplKey($id)]);$json=$q->fetchColumn();
        if(!is_string($json))return self::DEFAULTS[$id];
        $v=json_decode($json,true);if(!is_array($v))throw new \RuntimeException('Mail template settings damaged');
        self::validateTemplate((string)($v['subject']??''),(string)($v['body']??''));
        return ['name'=>self::DEFAULTS[$id]['name'],'subject'=>$v['subject'],'body'=>$v['body']];
    }
    public static function validateTemplate(string $subject,string $body):void {
        if(trim($subject)===''||trim($body)===''||mb_strlen($subject)>160||mb_strlen($body)>4000||preg_match('/[\x00-\x1F\x7F]/',$subject)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$body))throw new \InvalidArgumentException('メールの件名・本文を確認してください');
        preg_match_all('/\{\{([^{}]+)\}\}/u',$subject."\n".$body,$matches);
        foreach($matches[1] as $name)if(!in_array($name,self::FIELDS,true))throw new \InvalidArgumentException('未対応のテンプレート変数です: '.$name);
        // Also reject broken opening/closing template delimiters.
        $clean=preg_replace('/\{\{(?:site_name|user_name|event_time|detail|url)\}\}/','',$subject."\n".$body);
        if(str_contains((string)$clean,'{{')||str_contains((string)$clean,'}}'))throw new \InvalidArgumentException('テンプレート変数の形式を確認してください');
    }
    public static function saveTemplate(PDO $db,string $id,string $subject,string $body,int $actorId):void {
        if(!isset(self::DEFAULTS[$id]))throw new \InvalidArgumentException('Unknown notification type');
        self::validateTemplate($subject,$body);
        $json=json_encode(['subject'=>$subject,'body'=>$body],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $db->beginTransaction();
        try{
            $db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')->execute([self::tplKey($id),$json]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$actorId,'mail.template.updated','mail_template',$id,'{}']);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Default ON for each category; global notify_email=0 always wins. */
    public static function preferences(PDO $db,int $userId):array {
        $prefs=array_fill_keys(array_keys(self::DEFAULTS),true);
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? LIMIT 1');$q->execute([self::prefKey($userId)]);
        $raw=$q->fetchColumn();
        if(is_string($raw)){
            $data=json_decode($raw,true);
            if(!is_array($data))throw new \RuntimeException('Invalid mail preference format');
            foreach($prefs as $id=>$_)if(array_key_exists($id,$data))$prefs[$id]=($data[$id]===true);
        }
        return $prefs;
    }
    public static function savePreferences(PDO $db,int $userId,array $prefs):void {
        if($userId<1)throw new \InvalidArgumentException('Invalid user');
        $filtered=[];foreach(self::DEFAULTS as $id=>$_)$filtered[$id]=($prefs[$id]??false)===true;
        $json=json_encode($filtered,JSON_THROW_ON_ERROR);
        $db->beginTransaction();
        try{
            $db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')->execute([self::prefKey($userId),$json]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$userId,'mail.preference.updated','user',(string)$userId,'{}']);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function render(string $template,array $values):string {
        $dict=[];foreach(self::FIELDS as $field){$v=$values[$field]??'';$dict['{{'.$field.'}}']=is_string($v)?mb_substr($v,0,500):'';}
        return strtr($template,$dict);
    }
    /** Never abort the primary action on mail failure. Do not send inside a DB transaction. */
    public static function notify(PDO $db,int $userId,string $id,array $details=[]):bool {
        if(!isset(self::DEFAULTS[$id])||$db->inTransaction())return false;
        try{
            if(!MailConfig::configured())return false;
            $q=$db->prepare("SELECT u.email,u.display_name,u.status,COALESCE(m.notify_email,0) AS enabled FROM users u LEFT JOIN message_user_settings m ON m.user_id=u.id WHERE u.id=? LIMIT 1");
            $q->execute([$userId]);$u=$q->fetch(PDO::FETCH_ASSOC);
            if(!$u||$u['status']!=='active'||(int)$u['enabled']!==1||!filter_var((string)$u['email'],FILTER_VALIDATE_EMAIL)||!self::preferences($db,$userId)[$id])return false;
            if($id==='login'&&!Throttle::allow('mail-login-user:'.$userId,1,600))return false;
            $t=self::template($db,$id);
            $base=(string)(Database::settings($db,'site.url')??'');
            if(!preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?(?:/.*)?\z~D',$base))return false;
            $url=(string)($details['url']??rtrim($base,'/').'/account/center');
            if(!str_starts_with($url,rtrim($base,'/').'/'))$url=rtrim($base,'/').'/account/center';
            $fields=[
                'site_name'=>'キミの未来へ',
                'user_name'=>(string)$u['display_name'],
                'event_time'=>gmdate('Y-m-d H:i:s',time()+9*3600).' JST',
                'detail'=>(string)($details['detail']??''),
                'url'=>$url,
            ];
            SmtpMailer::send((string)$u['email'],self::render($t['subject'],$fields),self::render($t['body'],$fields));
            TokenService::deliveryEvent($db,$userId,'notice_'.$id,'sent');return true;
        }catch(\Throwable $e){
            error_log('KMM optional notification failed ['.$id.']: '.get_class($e));
            try{TokenService::deliveryEvent($db,$userId,'notice_'.$id,'failed');}catch(\Throwable){}
            return false;
        }
    }
}
