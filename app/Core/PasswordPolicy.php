<?php
declare(strict_types=1);
namespace KMM\Core;

/** New passwords: at least 8 allowed characters and two of A-Z/a-z, digits, symbols.
 * Existing password hashes remain valid; login does not retroactively reject them.
 */
final class PasswordPolicy {
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 256;
    public const PUNCTUATION = '~!@#$%^&*()_-+=[]{}<>,./?;:\'"`|';
    public static function valid(string $password): bool {
        $len=strlen($password);
        if($len<self::MIN_LENGTH||$len>self::MAX_LENGTH)return false;
        $hasLetter=preg_match('/[A-Za-z]/',$password)===1;
        $hasDigit=preg_match('/[0-9]/',$password)===1;
        $hasPunct=strpbrk($password,self::PUNCTUATION)!==false;
        if((int)$hasLetter+(int)$hasDigit+(int)$hasPunct<2)return false;
        // Reject controls, whitespace, non-ASCII and unlisted symbols.
        for($i=0;$i<$len;$i++){
            $char=$password[$i];
            if(!ctype_alpha($char)&&!ctype_digit($char)&&!str_contains(self::PUNCTUATION,$char))return false;
        }
        return true;
    }
    public static function hint():string {
        return '8文字以上。英字・数字・記号のうち2種類以上を使用してください（記号は指定されたASCII記号のみ）。';
    }
}
