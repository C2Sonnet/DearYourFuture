<?php
declare(strict_types=1);
namespace KMM\Core;

final class MessageData {
    public const PAGE_SIZE=25;
    public static function kind(string $kind):string {
        if(!in_array($kind,['direct','system','company_seed_update'],true))throw new \InvalidArgumentException('Invalid message kind');
        return $kind;
    }
    public static function text(mixed $text,int $max,bool $mandatory=false):string {
        if(!is_string($text)||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Invalid message text');
        // Reject control bytes before trim(): PHP trim() otherwise silently removes
        // trailing NUL or other invalid controls and bypasses the validation below.
        if(preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new \InvalidArgumentException('Message size or characters are invalid');
        $text=trim(str_replace(["\r\n","\r"],"\n",$text));
        if(preg_match_all('/./us',$text)>$max||($mandatory&&$text===''))throw new \InvalidArgumentException('Message size or characters are invalid');
        return $text;
    }
    public static function id(string $id):int {
        if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$id))throw new \InvalidArgumentException('Invalid message ID');
        return (int)$id;
    }
    public static function page(mixed $page):int {
        return is_string($page)&&preg_match('/\A[1-9][0-9]{0,3}\z/D',$page)?min((int)$page,1000):1;
    }
    public static function action(string $action):string {
        if(!in_array($action,['read','unread','trash','restore','purge','star','unstar','report'],true))throw new \InvalidArgumentException('Unsupported action');
        return $action;
    }
    public static function internalLink(mixed $link):string {
        if(!is_string($link) || strlen($link)>300 || !preg_match('~\A/(?!/)[a-z0-9/_?&=.%+-]*\z~iD',$link)||str_contains($link,'..')||preg_match('/%(?:2f|5c|0a|0d)/i',$link))throw new \InvalidArgumentException('Unsafe internal link');
        // Some browsers/proxies decode path escapes before routing. Reject encoded dots
        // and the percent escape itself in the PATH to prevent single/double-encoded
        // traversal (e.g. /x/%2e%2e/admin or /x/%252e%252e/admin).
        $path=explode('?',$link,2)[0];
        if(preg_match('/%(?:2e|25)/i',$path))throw new \InvalidArgumentException('Unsafe internal link');
        return $link;
    }
    public static function kindLabel(string $kind):string {return match(self::kind($kind)){'direct'=>'会員メッセージ','system'=>'システムから','company_seed_update'=>'企業情報の更新'};}
    public static function bodyHtml(string $body,bool $system=false):string {
        if(!$system)return nl2br(Security::h($body),false);
        $lines=explode("\n",$body);foreach($lines as &$line){
            if(preg_match('~\A(確認先[：:]\s*)(/[^\s]+)\s*\z~u',trim($line),$m)){
                try{$path=self::internalLink($m[2]);if(!preg_match('~\A/(?:companies|jobs)(?:/research)?(?:/[A-Za-z0-9-]+)?\z~D',$path))throw new \InvalidArgumentException();$line=Security::h($m[1]).'<a href="'.Security::h($path).'">詳細を開く</a>';continue;}catch(\Throwable){}
            }
            $line=Security::h($line);
        }unset($line);return implode("<br>\n",$lines);
    }
    public static function badge(int $unread):string {return $unread>99?'99+':(string)max(0,$unread);}
}
