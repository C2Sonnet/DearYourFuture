<?php
declare(strict_types=1);
namespace KMM\Core;

/** User-owned application data. A company can have multiple independent job tracks. */
final class ApplicationData {
    public const STATUSES=['consideration','applied','screening','rejected','accepted'];
    public const EVENTS=['note','deadline','information_session','document','contact','interview','result','status_change','other'];
    public const LABELS=[
        'consideration'=>'検討リスト','applied'=>'エントリー済','screening'=>'選考中',
        'rejected'=>'選考終了','accepted'=>'合格',
    ];
    public const STATUS_HELP='「選考終了」は、この応募の選考が終了した記録です。企業の募集終了や、ご本人の応募資格の判定とは別です。応募の状態は本人専用です。';
    public const MESSAGES=[
        'consideration'=>'気になる企業を、一つずつ見ていきましょう。',
        'applied'=>'一歩進めました。応募内容と次の予定を確認しましょう。',
        'screening'=>'準備したことを大切に。次の予定を一つずつ整理しましょう。',
        'rejected'=>'ここまでよく頑張りました。また少しずつ進めていきましょう。',
        'accepted'=>'おめでとうございます！ここまでの頑張りが形になりました。',
    ];
    public static function status(mixed $value):string {
        if(!is_string($value)||!in_array($value,self::STATUSES,true))throw new \InvalidArgumentException('Invalid application status');
        return $value;
    }
    public static function event(mixed $value):string {
        if(!is_string($value)||!in_array($value,self::EVENTS,true)||$value==='status_change')throw new \InvalidArgumentException('Invalid manual event kind');
        return $value;
    }
    public static function date(mixed $value):?string {
        if($value===null||$value==='')return null;
        if(!is_string($value)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new \InvalidArgumentException('Invalid event date');
        return $value;
    }
    public static function time(mixed $value,?string $date):?string {
        if($value===null||$value==='')return null;
        if($date===null||!is_string($value)||!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D',$value))throw new \InvalidArgumentException('Time needs a valid date');
        return $value.':00';
    }
    public static function fields(array $input):array {
        $kind=self::event($input['event_kind']??'note');
        $title=BusinessData::text($input['title']??null,180,true);
        $memo=BusinessData::text($input['memo']??'',3000);
        $on=self::date($input['event_on']??null);
        return ['event_kind'=>$kind,'title'=>$title,'memo'=>$memo,'event_on'=>$on,'event_time'=>self::time($input['event_time']??null,$on)];
    }
    public static function message(string $status):string {return self::MESSAGES[self::status($status)];}
    public static function css(string $status):string {return 'application-'.self::status($status);}
    public static function id(string $id):string {return BusinessData::id($id);}
    public static function page(mixed $value):int {
        return is_string($value)&&preg_match('/\A[1-9]\d{0,3}\z/D',$value)?min(1000,(int)$value):1;
    }
}
