<?php
declare(strict_types=1);
namespace KMM\Core;

/** Validates untrusted message form/query inputs before any coercion or DB access. */
final class MessageInput {
    public static function text(mixed $value,int $maxBytes=4200):string {
        if(!is_string($value)||strlen($value)>$maxBytes||preg_match('//u',$value)!==1
            ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))
            throw new \InvalidArgumentException('入力形式または文字数を確認してください');
        return $value;
    }
    public static function query(mixed $value,int $maxBytes=100):string {
        $query=self::text($value,$maxBytes);
        if(preg_match('/[\r\n\t]/',$query))throw new \InvalidArgumentException('検索語に改行や制御文字は使えません');
        return trim($query);
    }
    public static function checked(array $post,string $key):bool {
        if(!array_key_exists($key,$post))return false;
        if($post[$key]!=='1')throw new \InvalidArgumentException('チェック項目の形式を確認してください');
        return true;
    }
    public static function csrf(mixed $value):bool {
        return is_string($value)&&Session::validCsrf($value);
    }
}
