<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Site-internal MinaHelper greeting. Idempotent even if the client retries registration. */
final class WelcomeMessage {
    public const SETTING='messages.welcome_template_v1';
    public const DEFAULT_SUBJECT='キミの未来へ、ようこそ！';
    public const DEFAULT_BODY="ようこそ、{name}さん！\n\nここは、あなたの就職活動を一歩ずつ進めるための場所です。企業や求人を調べ、気になった仕事をお気に入りに登録したり、応募状況・予定を自分のペースで整理できます。\n\nまずは「ユーザーセンター」から使い方ガイドを確認してみてください。困ったときは無理せず、一つずつ確認していきましょう。\n\n※ 掲載された求人が現在募集中であることや応募資格を保証するものではありません。応募の際には必ず原典をご確認ください。\n\nMinaHelper より";
    public static function template(PDO $db): array {
        // The key does not exist on installations predating this feature.
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');
        $q->execute([self::SETTING]);
        $raw=$q->fetchColumn();
        $v=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($v))return ['subject'=>self::DEFAULT_SUBJECT,'body'=>self::DEFAULT_BODY];
        $s=$v['subject']??null;$b=$v['body']??null;
        return is_string($s)&&is_string($b)&&trim($s)!==''&&trim($b)!==''?['subject'=>$s,'body'=>$b]:['subject'=>self::DEFAULT_SUBJECT,'body'=>self::DEFAULT_BODY];
    }
    public static function validate(string $subject,string $body):array {
        $subject=trim($subject);$body=trim($body);
        if($subject===''||mb_strlen($subject)>80||$body===''||mb_strlen($body)>1000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$subject.$body))throw new \InvalidArgumentException('テンプレートの長さ・内容を確認してください。');
        return ['subject'=>$subject,'body'=>$body];
    }
    /** Call while inserting the account, inside the SAME transaction. No mail transport required. */
    public static function enqueue(PDO $db,int $userId,string $displayName):void {
        if($userId<1)throw new \InvalidArgumentException('Unknown recipient');
        $t=self::template($db);
        $replacement=mb_substr($displayName,0,120);
        $subject=str_replace('{name}',$replacement,$t['subject']);
        $body=str_replace('{name}',$replacement,$t['body']);
        $key='welcome:'.$userId.':v1';
        $q=$db->prepare("INSERT INTO messages(kind,sender_user_id,subject,body,system_event_key) VALUES ('system',NULL,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $q->execute([$subject,$body,$key]);
        $id=(int)$db->lastInsertId();
        $db->prepare('INSERT IGNORE INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)')->execute([$id,$userId]);
    }
}
