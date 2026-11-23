<?php
declare(strict_types=1);
namespace KMM\Core;
/** Reject active content, files too large to scan, path-like filenames and unaudited publication. */
final class LibraryData {
    public const TYPES=['recruitment','company','ir','financial','job_description','other'];
    public const MIME=['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','text/plain'=>'txt'];
    public const TYPE_LABELS=['recruitment'=>'採用資料','company'=>'企業情報','ir'=>'IR資料','financial'=>'財務資料','job_description'=>'仕事内容・求人票','other'=>'その他'];
    public static function typeLabel(?string $value):string {return self::TYPE_LABELS[$value??'other']??'その他';}
    public const MAX_BYTES=8*1024*1024;
    public const PUBLIC_SOURCE_TYPES=['company_official','recruitment_official','job_board','public_agency','ir','social_official','community'];
    public static function fields(array $raw):array {
        return [
            'title'=>BusinessData::text($raw['title']??null,240,true),
            'description'=>BusinessData::text($raw['description']??'',3000),
            'document_type'=>BusinessData::select($raw['document_type']??'other',self::TYPES),
            'publication_state'=>BusinessData::select($raw['publication_state']??'private',['private','public']),
            'source_id'=>self::sourceId($raw['source_id']??null),
        ];
    }
    public static function sourceId(mixed $v):?string {
        if($v===null||$v==='')return null;
        if(!is_string($v))throw new \InvalidArgumentException('Invalid citation id');
        return BusinessData::id($v);
    }
    public static function safeName(mixed $name):string {
        if(!is_string($name)||$name===''||strlen($name)>240||preg_match('~[/\\\\\x00-\x1f\x7f]~',$name))throw new \InvalidArgumentException('Invalid uploaded filename');
        return $name;
    }
    public static function acceptMime(string $mime):string {
        if(!isset(self::MIME[$mime]))throw new \InvalidArgumentException('Unsupported document type');
        return self::MIME[$mime];
    }
    public static function publicationAllowed(array $doc,?array $source,?array $company):bool {
        if(($doc['publication_state']??null)!=='public')return false;
        if(($company['visibility']??null)!=='published')return false;
        if($source===null||($source['publication_state']??null)!=='public'||($source['archived_at']??null)!==null)return false;
        return in_array($source['source_type']??null,self::PUBLIC_SOURCE_TYPES,true);
    }
}
