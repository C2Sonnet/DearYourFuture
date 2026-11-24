<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Licence/redistribution confirmation is distinct from citation trustworthiness. */
final class LibraryMetadata {
    public const RELIABILITY=['primary','secondary','community','unknown'];
    public const PERMISSIONS=['unknown','approved','denied'];
    public const RELIABILITY_LABELS=['primary'=>'一次資料','secondary'=>'二次資料','community'=>'コミュニティ情報','unknown'=>'未確認'];
    public const PERMISSION_LABELS=['unknown'=>'未確認','approved'=>'再配布許可あり','denied'=>'再配布不可'];
    public static function languageLabel(?string $tag):string {
        if($tag===null||$tag===''||$tag==='und')return '未確認';
        return match($tag){'ja'=>'日本語','en'=>'英語','ko'=>'韓国語','zh'=>'中国語','fr'=>'フランス語','de'=>'ドイツ語',default=>'その他（言語コード：'.$tag.'）'};
    }
    public static function permissionLabel(?string $value):string {return self::PERMISSION_LABELS[$value??'unknown']??'未確認';}
    public static function reliabilityLabel(?string $value):string {return self::RELIABILITY_LABELS[$value??'unknown']??'未確認';}
    public static function normalize(array $raw):array {
        $language=$raw['language_tag']??'und';
        if(!is_string($language)||!preg_match('/\A(?:und|[a-z]{2,3}(?:-[A-Z]{2})?)\z/D',$language))throw new \InvalidArgumentException('Invalid language tag');
        $issuer=BusinessData::text($raw['issuer']??'',240)??'';
        $reliability=BusinessData::select($raw['reliability']??'unknown',self::RELIABILITY);
        $permission=BusinessData::select($raw['distribution_permission']??'unknown',self::PERMISSIONS);
        $checked=BusinessData::optionalDate($raw['permission_checked_on']??'');
        $note=BusinessData::text($raw['permission_note']??'',1500);
        if($permission==='approved'&&($checked===null||$note===null||trim($note)===''))throw new \InvalidArgumentException('Published document requires dated, recorded permission evidence');
        return compact('language','issuer','reliability','permission','checked','note');
    }
    public static function canDistribute(?array $meta):bool {
        return $meta!==null&&($meta['distribution_permission']??null)==='approved'&&!empty($meta['permission_checked_on'])&&trim((string)($meta['permission_note']??''))!=='';
    }
    public static function get(PDO $db,string $docId):?array {
        $q=$db->prepare('SELECT * FROM library_document_metadata WHERE document_id=?');$q->execute([BusinessData::id($docId)]);
        return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function set(PDO $db,string $docId,int $actor,array $raw):void {
        if($actor<1)throw new \InvalidArgumentException('Not permitted');
        $v=self::normalize($raw);$docId=BusinessData::id($docId);
        $submitted=$raw['meta_revision']??null;
        if(!is_string($submitted)||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$submitted))throw new \InvalidArgumentException('Invalid metadata revision');
        $expected=(int)$submitted;
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id FROM library_documents WHERE id=? AND archived_at IS NULL FOR UPDATE');$q->execute([$docId]);
            if(!$q->fetch(PDO::FETCH_ASSOC))throw new \InvalidArgumentException('Document unavailable');
            $q=$db->prepare('SELECT revision FROM library_document_metadata WHERE document_id=? FOR UPDATE');$q->execute([$docId]);
            $current=$q->fetchColumn();
            if($current===false){
                if($expected!==0)throw new \RuntimeException('Metadata changed');
                $q=$db->prepare('INSERT INTO library_document_metadata(document_id,language_tag,issuer,reliability,distribution_permission,permission_checked_on,permission_note,updated_by) VALUES (?,?,?,?,?,?,?,?)');
                $q->execute([$docId,$v['language'],$v['issuer'],$v['reliability'],$v['permission'],$v['checked'],$v['note'],$actor]);
            }else{
                if((int)$current!==$expected)throw new \RuntimeException('Stale metadata version');
                $q=$db->prepare('UPDATE library_document_metadata SET language_tag=?,issuer=?,reliability=?,distribution_permission=?,permission_checked_on=?,permission_note=?,updated_by=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE document_id=? AND revision=?');
                $q->execute([$v['language'],$v['issuer'],$v['reliability'],$v['permission'],$v['checked'],$v['note'],$actor,$docId,$expected]);
                if($q->rowCount()!==1)throw new \RuntimeException('Concurrent metadata change');
            }
            $q=$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)');
            $q->execute([$actor,'library.permission.reviewed','library_document',$docId,json_encode(['permission'=>$v['permission'],'checked_on'=>$v['checked']],JSON_THROW_ON_ERROR)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

}
