<?php
declare(strict_types=1);
namespace KMM\Core;

/** Strict SMTP with TLS and sanitized phase-specific failure reporting. */
final class SmtpMailer {
    public static function send(string $recipient,string $subject,string $message):void {
        $phase='validate';$code=null;$socketErrno=null;$socket=null;$accepted=false;
        try {
            if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$recipient.$subject))throw new \InvalidArgumentException('Invalid mail header');
            $phase='configuration';$cfg=MailConfig::load();if($cfg===null)throw new \RuntimeException('SMTP unavailable');
            $context=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,
                'peer_name'=>$cfg['host'],'SNI_enabled'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLS_CLIENT]];
            if($cfg['ca_mode']==='custom')$context['ssl']['cafile']=SiteState::root().'/storage/private/mail-ca.pem';
            $streamContext=stream_context_create($context);
            $scheme=$cfg['tls_mode']==='implicit'?'tls':'tcp';
            $phase='connect';$errno=0;$errstr='';
            $socket=@stream_socket_client($scheme.'://'.$cfg['host'].':'.$cfg['port'],$errno,$errstr,10,STREAM_CLIENT_CONNECT,$streamContext);
            if($socket===false){$socketErrno=(int)$errno;throw new \RuntimeException('SMTP connection failed');}
            stream_set_timeout($socket,12);
            $phase='greeting';self::expect($socket,[220],$code);
            $phase='ehlo';self::command($socket,'EHLO localhost',[250],$code);
            if($cfg['tls_mode']==='starttls') {
                $phase='starttls';self::command($socket,'STARTTLS',[220],$code);
                $phase='tls_negotiation';if(!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('SMTP TLS negotiation failed');
                $phase='tls_ehlo';self::command($socket,'EHLO localhost',[250],$code);
            }
            if($cfg['username']!=='') {
                $phase='auth_login';self::command($socket,'AUTH LOGIN',[334],$code);
                $phase='auth_username';self::command($socket,base64_encode($cfg['username']),[334],$code);
                $phase='auth_password';self::command($socket,base64_encode($cfg['password']),[235],$code);
            }
            $phase='mail_from';self::command($socket,'MAIL FROM:<'.$cfg['from'].'>',[250],$code);
            $phase='rcpt_to';self::command($socket,'RCPT TO:<'.$recipient.'>',[250,251],$code);
            $phase='data';self::command($socket,'DATA',[354],$code);
            $label='=?UTF-8?B?'.base64_encode($cfg['sender_name']).'?=';
            $subjectHeader='=?UTF-8?B?'.base64_encode($subject).'?=';
            $body=chunk_split(base64_encode($message),76,"\r\n");
            $headers=['From: '.$label.' <'.$cfg['from'].'>','To: <'.$recipient.'>','Subject: '.$subjectHeader,
                'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64','Auto-Submitted: auto-generated',
                'Date: '.gmdate('D, d M Y H:i:s +0000')];
            $phase='data_accepted';self::write($socket,implode("\r\n",$headers)."\r\n\r\n".$body."\r\n.\r\n");
            self::expect($socket,[250],$code);$accepted=true;
            SmtpAudit::record('accepted','data_accepted',$code);
            // Acceptance of DATA (250) is the delivery boundary. QUIT failure must not
            // cause an ambiguous retry or report a false failure after acceptance.
            try {self::command($socket,'QUIT',[221],$code);}catch(\Throwable){error_log('KMM SMTP QUIT failed after message acceptance');}
        } catch(\Throwable $e){
            if(!$accepted){if($phase==='greeting'){$reason=$e->getMessage();if(str_contains($reason,'timed out'))$phase='greeting_timeout';elseif(str_contains($reason,'closed'))$phase='greeting_closed';elseif(str_contains($reason,'invalid'))$phase='greeting_invalid';elseif(str_contains($reason,'rejected'))$phase='greeting_unexpected_code';}SmtpAudit::record('failed',$phase,$code,$socketErrno,get_class($e));}
            throw new SmtpDeliveryException($phase,$code,$socketErrno);
        } finally {if(is_resource($socket))fclose($socket);}
    }
    private static function write($stream,string $bytes):void {
        $left=strlen($bytes);$offset=0;
        while($left>0){$n=@fwrite($stream,substr($bytes,$offset));if($n===false||$n===0)throw new \RuntimeException('SMTP write failed');$offset+=$n;$left-=$n;}
    }
    private static function expect($stream,array $codes,?int &$responseCode=null):void {
        $seen=0;$code=0;
        do {
            $line=@fgets($stream,2048);if($line===false){$meta=stream_get_meta_data($stream);if(($meta['timed_out']??false)===true)throw new \RuntimeException('SMTP greeting timed out');if(feof($stream))throw new \RuntimeException('SMTP connection closed');throw new \RuntimeException('SMTP response invalid');}if(!preg_match('/\A([0-9]{3})([ -])/',$line,$m))throw new \RuntimeException('SMTP response invalid');
            $code=(int)$m[1];$responseCode=$code;$continued=$m[2]==='-';$seen++;
            if($seen>30)throw new \RuntimeException('Too many SMTP response lines');
        }while($continued);
        if(!in_array($code,$codes,true))throw new \RuntimeException('SMTP rejected request');
    }
    private static function command($stream,string $data,array $codes,?int &$code=null):void {self::write($stream,$data."\r\n");self::expect($stream,$codes,$code);}
}
