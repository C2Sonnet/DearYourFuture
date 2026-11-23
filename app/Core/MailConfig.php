<?php
declare(strict_types=1);
namespace KMM\Core;

/** Secrets are stored off-document-root with authenticated encryption and strict permissions. */
final class MailConfig {
    public static function path(): string {return SiteState::root().'/storage/private/mail.enc';}
    private static function key(): string {
        $key=@file_get_contents(SiteState::root().'/storage/private/app.key');
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Mail key missing');
        return hash_hkdf('sha256',$key,32,'KMM smtp secrets v1');
    }
    public static function validate(array $cfg): array {
        $host=trim((string)($cfg['host']??''));$port=(int)($cfg['port']??0);
        $user=(string)($cfg['username']??'');$password=(string)($cfg['password']??'');
        $sender=trim((string)($cfg['from']??''));$label=trim((string)($cfg['sender_name']??'キミの未来へ'));
        $mode=(string)($cfg['tls_mode']??'');$ca=(string)($cfg['ca_mode']??'system');
        if(!preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/',$host)||$port<1||$port>65535 ||
            !filter_var($sender,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$sender.$label.$user.$password)||
            !in_array($mode,['starttls','implicit'],true)||!in_array($ca,['system','custom'],true)||
            strlen($label)>160||strlen($user)>256||strlen($password)>512||$password==='') {
            throw new \InvalidArgumentException('Invalid SMTP configuration');
        }
        if($ca==='custom' && !is_readable(SiteState::root().'/storage/private/mail-ca.pem'))throw new \InvalidArgumentException('Custom CA missing');
        return ['host'=>$host,'port'=>$port,'username'=>$user,'password'=>$password,'from'=>$sender,
            'sender_name'=>$label,'tls_mode'=>$mode,'ca_mode'=>$ca];
    }
    public static function save(array $cfg): void {
        if(!extension_loaded('sodium'))throw new \RuntimeException('Sodium required');
        $data=json_encode(self::validate($cfg),JSON_THROW_ON_ERROR);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=sodium_crypto_secretbox($data,$nonce,self::key());
        SiteState::writeAtomic(self::path(),base64_encode($nonce.$cipher));
    }
    public static function load(): ?array {
        $raw=@file_get_contents(self::path());if($raw===false)return null;
        $bin=base64_decode($raw,true);
        if(!is_string($bin)||strlen($bin)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES)throw new \RuntimeException('Invalid mail settings');
        $n=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $data=sodium_crypto_secretbox_open(substr($bin,$n),substr($bin,0,$n),self::key());
        if($data===false)throw new \RuntimeException('Mail settings integrity failure');
        $decoded=json_decode($data,true,512,JSON_THROW_ON_ERROR);
        return self::validate($decoded);
    }
    public static function configured(): bool {return is_file(self::path());}
}
