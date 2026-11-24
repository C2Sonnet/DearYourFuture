<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Public pre-launch display; no access to private site data is granted to visitors. */
final class PreviewCountdown {
    public const DEFAULT_END_JST='2026-11-23T16:00:00+09:00';
    public const DEFAULT_ENABLED=true;
    private const ENABLED_KEY='site.countdown.enabled';
    private const END_KEY='site.countdown.ends_at';
    private static function value(PDO $db,string $key,mixed $fallback):mixed {
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? LIMIT 1');$q->execute([$key]);
        $raw=$q->fetchColumn();return is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$fallback;
    }
    public static function state(PDO $db):array {
        $enabled=self::value($db,self::ENABLED_KEY,self::DEFAULT_ENABLED);
        $raw=self::value($db,self::END_KEY,self::DEFAULT_END_JST);
        if(!is_bool($enabled)||!is_string($raw))throw new \RuntimeException('Invalid countdown settings');
        $end=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$raw);
        if(!$end||$end->format('Y-m-d\TH:i:sP')!==$raw)throw new \RuntimeException('Invalid countdown end time');
        if($enabled && time()>=$end->getTimestamp()){
            // Persist OFF once the deadline passes; reruns are harmless, no cron needed.
            try{self::upsert($db,self::ENABLED_KEY,false);}
            catch(\Throwable $e){error_log('KMM countdown automatic-off persistence failed: '.get_class($e));}
            $enabled=false;
        }
        return ['enabled'=>$enabled,'end'=>$end,'ends_at'=>$raw];
    }
    private static function upsert(PDO $db,string $key,mixed $value):void {
        $db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES(?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')
           ->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);
    }
    public static function configure(PDO $db,bool $enabled,string $jstLocal):void {
        // Explicit date-time local input, never depend on the operator's timezone.
        if(!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$jstLocal))throw new \InvalidArgumentException('Invalid local date');
        $end=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $jstLocal, new \DateTimeZone('Asia/Tokyo'));
        if(!$end||$end->format('Y-m-d\TH:i')!==$jstLocal)throw new \InvalidArgumentException('Invalid local date');
        if($enabled && $end->getTimestamp()<=time())throw new \InvalidArgumentException('Countdown deadline is in the past');
        $db->beginTransaction();
        try{
            self::upsert($db,self::END_KEY,$end->format('Y-m-d\TH:i:sP'));
            self::upsert($db,self::ENABLED_KEY,$enabled);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function isExempt(string $path):bool {
        // Authentication and recovery must remain reachable; no anonymous app data is exposed.
        return in_array($path,['/account/login','/admin/login','/account/forgot-password','/account/reset-password','/account/verify-email'],true);
    }
    public static function handle(string $method,\DateTimeImmutable $end):void {
        if($method!=='GET'){
            if(str_starts_with((string)($_SERVER['REQUEST_URI']??''),'/api/')){http_response_code(503);header('Content-Type: application/json');echo '{"error":"countdown_active"}';return;}
            header('Location: /countdown',true,303);return;
        }
        header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');
        $iso=$end->format('Y-m-d\TH:i:sP');$jst=$end->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y年n月j日 H:i');
        $html='<section class="countdown-scene" aria-labelledby="countdown-heading"><div class="countdown-card">'
            .'<p class="countdown-eyebrow">✿ COMING SOON · キミの未来へ</p>'
            .'<h1 id="countdown-heading">ただいま、サイトを準備中です。</h1>'
            .'<p class="countdown-intro">あなたの未来につながる、小さな一歩のために。<br>公開まで、もう少しだけお待ちください。</p>'
            .'<div class="countdown-digits" uk-countdown="date: '.Security::h($iso).'" data-countdown-ends="'.Security::h($iso).'" role="timer" aria-label="公開までの残り時間">'
            .'<div class="countdown-unit"><span class="uk-countdown-number uk-countdown-days">00</span><span class="uk-countdown-label">日</span></div>'
            .'<span class="uk-countdown-separator" aria-hidden="true">:</span>'
            .'<div class="countdown-unit"><span class="uk-countdown-number uk-countdown-hours">00</span><span class="uk-countdown-label">時間</span></div>'
            .'<span class="uk-countdown-separator" aria-hidden="true">:</span>'
            .'<div class="countdown-unit"><span class="uk-countdown-number uk-countdown-minutes">00</span><span class="uk-countdown-label">分</span></div>'
            .'<span class="uk-countdown-separator" aria-hidden="true">:</span>'
            .'<div class="countdown-unit"><span class="uk-countdown-number uk-countdown-seconds">00</span><span class="uk-countdown-label">秒</span></div>'
            .'</div><p class="countdown-deadline">公開予定：'.Security::h($jst).'（日本時間）</p>'
            .'<p class="countdown-caption">すでにアカウントをお持ちの方はログインできます。</p>'
            .'<a class="btn primary" href="/account/login">ログインページへ</a>'
            .'</div></section>';
        // All CSS/JS local, compatible with uk-countdown selectors and a strict CSP.
        Kernel::layout('サイト準備中',$html,false);
    }
}
