<?php
declare(strict_types=1);
namespace KMM\Core;

/** Sanitized, access-controlled SMTP submission audit outside the web root. */
final class SmtpAudit {
    public static function path():string{return SiteState::root().'/storage/private/smtp-delivery.jsonl';}
    public static function record(string $outcome,string $phase,?int $code=null,?int $errno=null,string $kind=''):void {
        if(!in_array($outcome,['accepted','failed'],true))return;
        $record=['utc'=>gmdate('c'),'result'=>$outcome,'phase'=>preg_replace('/[^a-z0-9_-]/','',strtolower($phase)),
            'smtp_code'=>$code,'socket_errno'=>$errno,'error_type'=>preg_replace('/[^A-Za-z0-9_]/','', $kind)];
        try {
            $dir=dirname(self::path());if(!is_dir($dir)||!is_writable($dir))throw new \RuntimeException('private log directory unavailable');
            $path=self::path();$new=!is_file($path);
            if($new){$handle=@fopen($path,'x');if($handle!==false){@chmod($path,0600);fclose($handle);}}
            if(is_link($path)||!is_file($path))throw new \RuntimeException('log target invalid');
            // No email addresses, passwords, hostnames, SMTP server text, or email bodies.
            if(@file_put_contents($path,json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false)
                throw new \RuntimeException('log write failed');
            @chmod($path,0600);
        }catch(\Throwable){error_log('KMM sanitized SMTP audit unavailable');}
    }
    public static function recent(int $limit=15):array {
        $path=self::path();if(is_link($path)||!is_file($path)||!is_readable($path))return [];
        $handle=@fopen($path,'rb');if($handle===false)return [];
        try {
            $length=(int)(@filesize($path)?:0);$start=max(0,$length-32768);
            fseek($handle,$start);if($start>0)fgets($handle);
            $rows=[];while(($line=fgets($handle))!==false){$r=json_decode($line,true);if(is_array($r))$rows[]=$r;}
            return array_slice(array_reverse($rows),0,max(1,min($limit,30)));
        }finally{fclose($handle);}
    }
    public static function diagnostics():array {
        $private=SiteState::root().'/storage/private';$cfg=null;$configuration='未設定';
        try {$cfg=MailConfig::load();if($cfg!==null)$configuration='設定読込成功';}
        catch(\Throwable){$configuration='設定の復号または検証に失敗';}
        $transports=function_exists('stream_get_transports')?stream_get_transports():[];
        $fns=['stream_socket_client','stream_context_create','stream_socket_enable_crypto','stream_set_timeout','stream_get_transports','fwrite','fgets'];
        $status=['SMTP設定'=>$configuration,'OpenSSL拡張'=>extension_loaded('openssl')?'利用可能':'利用不可',
            'Sodium拡張'=>extension_loaded('sodium')?'利用可能':'利用不可',
            'TCPストリーム'=>in_array('tcp',$transports,true)?'利用可能':'利用不可',
            'TLSストリーム'=>in_array('tls',$transports,true)?'利用可能':'利用不可'];
        foreach($fns as $fn)$status['PHP '.$fn]=function_exists($fn)?'利用可能':'利用不可';
        $status['TLS形式']=$cfg!==null?($cfg['tls_mode']==='implicit'?'Implicit TLS':'STARTTLS'):'未判定';
        $status['ポートとTLS形式の目安']=$cfg===null?'未判定':($cfg['port']===587&&$cfg['tls_mode']==='implicit'?'587番は通常STARTTLSです。設定を確認':($cfg['port']===465&&$cfg['tls_mode']==='starttls'?'465番は通常Implicit TLSです。設定を確認':'代表的な不一致は検出されません（実接続は未検証）'));
        $status['CA証明書']=$cfg===null?'未判定':($cfg['ca_mode']==='custom'?(is_readable($private.'/mail-ca.pem')?'カスタムCA読取可能':'カスタムCA読取不可'):'OS証明書ストアを使用');
        $status['非公開ログ保存先']=is_dir($private)&&is_writable($private)?'書込可能':'書込不可';
        return $status;
    }
}
