<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Byte storage is outside public/; immutable versions and DB audit are append-only. */
final class LibraryRepository {
    public static function storageRoot():string{return SiteState::root().'/storage/private/library';}
    public static function storeUploadedFile(array $file):array {
        if(($file['error']??null)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Upload failed');
        $size=$file['size']??null;$tmp=$file['tmp_name']??null;
        if(!is_int($size)||$size<1||$size>LibraryData::MAX_BYTES||!is_string($tmp)||!is_uploaded_file($tmp))throw new \InvalidArgumentException('Invalid or oversized uploaded file');
        $name=LibraryData::safeName($file['name']??null);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(!is_string($mime))throw new \InvalidArgumentException('Unknown media type');
        $suffix=LibraryData::acceptMime($mime);
        if(filesize($tmp)!==$size)throw new \InvalidArgumentException('Upload size mismatch');
        if($mime==='image/png'||$mime==='image/jpeg')LibraryImagePolicy::validate($mime, @getimagesize($tmp));
        $hash=hash_file('sha256',$tmp);if($hash===false)throw new \RuntimeException('Cannot inspect uploaded file');
        $scan=self::scanUpload($tmp);
        if($scan==='infected')throw new \InvalidArgumentException('Upload was rejected by security scanner');
        $root=self::storageRoot();
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new \RuntimeException('Cannot make private storage');
        chmod($root,0700);$key=bin2hex(random_bytes(32));$dest=$root.'/'.$key;
        if(!move_uploaded_file($tmp,$dest))throw new \RuntimeException('Cannot securely store upload');
        chmod($dest,0600);
        return ['storage_key'=>$key,'original_name'=>$name,'media_type'=>$mime,'byte_size'=>$size,'sha256'=>$hash,'scan_status'=>$scan];
    }
    /** A missing, failed, slow or stalled scanner never means a clean file. */
    public static function scanUpload(string $path):string {
        $scanner='/usr/bin/clamscan';
        if(!is_executable($scanner))return 'pending';
        $pipes=[];
        $process=@proc_open([$scanner,'--no-summary','--',$path],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))return 'pending';
        try {
            if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);
            foreach([1,2] as $index)if(isset($pipes[$index])&&is_resource($pipes[$index]))stream_set_blocking($pipes[$index],false);
            $deadline=microtime(true)+30.0; // Conservative HTTP request deadline (seconds).
            while(true){
                $status=@proc_get_status($process);
                if(!is_array($status))return 'pending';
                if(!$status['running']){
                    // Drain errors/output without allocating an unbounded string.
                    return match((int)$status['exitcode']){0=>'clean',1=>'infected',default=>'pending'};
                }
                if(microtime(true)>=$deadline)return 'pending';
                $read=[];
                foreach([1,2] as $index)if(isset($pipes[$index])&&is_resource($pipes[$index])&&!feof($pipes[$index]))$read[]=$pipes[$index];
                if($read){
                    $write=null;$except=null;
                    $ready=@stream_select($read,$write,$except,0,200000);
                    if($ready===false)return 'pending';
                    foreach($read as $stream)@fread($stream,8192); // Discard safely; never collect untrusted scanner output.
                }else usleep(50000);
            }
        } finally {
            $status=@proc_get_status($process);
            if(is_array($status)&&$status['running']){
                @proc_terminate($process,15);
                $grace=microtime(true)+0.5;
                while(microtime(true)<$grace){
                    $check=@proc_get_status($process);
                    if(!is_array($check)||!$check['running'])break;
                    usleep(50000);
                }
                $check=@proc_get_status($process);
                if(is_array($check)&&$check['running'])@proc_terminate($process,9);
            }
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            @proc_close($process);
        }
    }
    public static function discard(string $key):void {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$key)===1){$p=self::storageRoot().'/'.$key;if(is_file($p))unlink($p);}
    }
    public static function create(PDO $db,string $companyId,array $fields,array $file,int $actor):string {
        BusinessData::id($companyId);$fields=LibraryData::fields($fields);
        $q=$db->prepare('SELECT * FROM companies WHERE id=? AND visibility<>\'archived\'');$q->execute([$companyId]);$company=$q->fetch(PDO::FETCH_ASSOC);
        if(!$company)throw new \InvalidArgumentException('Invalid company');
        if($fields['publication_state']==='public')throw new \InvalidArgumentException('Register privately, record distribution permission, then publish');
        $id=BusinessData::id();$db->beginTransaction();
        try {
            $q=$db->prepare('INSERT INTO library_documents(id,company_id,source_id,title,description,document_type,publication_state,created_by) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$id,$companyId,$fields['source_id'],$fields['title'],$fields['description'],$fields['document_type'],$fields['publication_state'],$actor]);
            self::insertVersion($db,$id,1,$file,$actor);
            self::event($db,$actor,$id,'create',null,['document'=>$fields,'file_sha256'=>$file['sha256']]);
            $db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function update(PDO $db,string $id,int $revision,array $fields,?array $file,int $actor):void {
        BusinessData::id($id);$fields=LibraryData::fields($fields);
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM library_documents WHERE id=? FOR UPDATE');$q->execute([$id]);$prev=$q->fetch(PDO::FETCH_ASSOC);
            if(!$prev||$prev['archived_at']!==null)throw new \InvalidArgumentException('Document unavailable');
            if((int)$prev['revision']!==$revision)throw new \RuntimeException('Stale document version');
            $c=$db->prepare('SELECT * FROM companies WHERE id=?');$c->execute([$prev['company_id']]);$company=$c->fetch(PDO::FETCH_ASSOC);
            if($fields['publication_state']==='public'){
                $check=$file??self::version($db,$id,(int)$prev['current_version']);
                self::assertPublic($db,$fields,$company?:null,$check,$id);
            }
            $next=(int)$prev['current_version']+($file===null?0:1);
            $q=$db->prepare('UPDATE library_documents SET source_id=?,title=?,description=?,document_type=?,publication_state=?,current_version=?,revision=revision+1,updated_by=? WHERE id=? AND revision=?');
            $q->execute([$fields['source_id'],$fields['title'],$fields['description'],$fields['document_type'],$fields['publication_state'],$next,$actor,$id,$revision]);
            if($q->rowCount()!==1)throw new \RuntimeException('Concurrent document update');
            if($file!==null)self::insertVersion($db,$id,$next,$file,$actor);
            self::event($db,$actor,$id,'update',$prev,['document'=>$fields,'file_sha256'=>$file['sha256']??null]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function archive(PDO $db,string $id,int $revision,int $actor):void {
        BusinessData::id($id);$db->beginTransaction();
        try{$q=$db->prepare('SELECT * FROM library_documents WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!$old||$old['archived_at']!==null||(int)$old['revision']!==$revision)throw new \RuntimeException('Stale document');
            $q=$db->prepare('UPDATE library_documents SET archived_at=UTC_TIMESTAMP(),revision=revision+1,updated_by=? WHERE id=? AND revision=?');$q->execute([$actor,$id,$revision]);
            self::event($db,$actor,$id,'archive',$old,['archived'=>true]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function list(PDO $db,bool $publicOnly,?string $companyId=null,int $limit=25,int $offset=0):array {
        if($limit<1||$limit>100||$offset<0)throw new \InvalidArgumentException('Invalid pagination');
        $w=['d.archived_at IS NULL'];$params=[];
        if($publicOnly){$w[]="EXISTS (SELECT 1 FROM library_document_versions v WHERE v.document_id=d.id AND v.version_no=d.current_version AND v.scan_status='clean')";$w[]="d.publication_state='public'";$w[]="c.visibility='published'";$w[]="s.publication_state='public'";$w[]="s.archived_at IS NULL";$w[]="s.source_type IN ('company_official','recruitment_official','job_board','public_agency','ir','social_official','community')";$w[]="EXISTS (SELECT 1 FROM library_document_metadata meta WHERE meta.document_id=d.id AND meta.distribution_permission='approved' AND meta.permission_checked_on IS NOT NULL AND meta.permission_note IS NOT NULL AND meta.permission_note<>'')";}
        if($companyId!==null){$w[]='d.company_id=?';$params[]=BusinessData::id($companyId);}
        $sql='SELECT d.*,c.display_name AS company_name FROM library_documents d JOIN companies c ON c.id=d.company_id LEFT JOIN sources s ON s.id=d.source_id WHERE '.implode(' AND ',$w).' ORDER BY d.updated_at DESC,d.id LIMIT '.$limit.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Public library search never returns unscanned/private or unpublished-company files. */
    public static function search(PDO $db,?string $companyId,array $raw,int $limit=25,int $offset=0):array {
        if($limit<1||$limit>100||$offset<0)throw new \InvalidArgumentException('Invalid pagination');
        $filter=self::normalizeSearch($raw);
        $w=["d.archived_at IS NULL","d.publication_state='public'","c.visibility='published'",
            "s.publication_state='public'","s.archived_at IS NULL",
            "s.source_type IN ('company_official','recruitment_official','job_board','public_agency','ir','social_official','community')",
            "EXISTS (SELECT 1 FROM library_document_metadata meta WHERE meta.document_id=d.id AND meta.distribution_permission='approved' AND meta.permission_checked_on IS NOT NULL AND meta.permission_note IS NOT NULL AND meta.permission_note<>'')",
            "EXISTS (SELECT 1 FROM library_document_versions v WHERE v.document_id=d.id AND v.version_no=d.current_version AND v.scan_status='clean')"];
        $args=[];
        if($companyId!==null){$w[]='d.company_id=?';$args[]=BusinessData::id($companyId);}
        if(isset($filter['q'])){$w[]="(d.title LIKE ? ESCAPE '!' OR d.description LIKE ? ESCAPE '!')";$escaped='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filter['q']).'%';array_push($args,$escaped,$escaped);}
        if(isset($filter['document_type'])){$w[]='d.document_type=?';$args[]=$filter['document_type'];}
        if(isset($filter['source_type'])){$w[]='s.source_type=?';$args[]=$filter['source_type'];}
        if(isset($filter['observed_after'])){$w[]='s.observed_on>=?';$args[]=$filter['observed_after'];}
        if(isset($filter['language_tag'])){$w[]='meta.language_tag=?';$args[]=$filter['language_tag'];}
        if(isset($filter['issuer'])){$w[]="meta.issuer LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filter['issuer']).'%';}
        if(isset($filter['reliability'])){$w[]='meta.reliability=?';$args[]=$filter['reliability'];}
        $query='SELECT d.*,c.display_name AS company_name,meta.language_tag,meta.issuer,meta.reliability FROM library_documents d JOIN companies c ON c.id=d.company_id JOIN sources s ON s.id=d.source_id JOIN library_document_metadata meta ON meta.document_id=d.id WHERE '.implode(' AND ',$w).' ORDER BY d.updated_at DESC,d.id LIMIT '.$limit.' OFFSET '.$offset;
        $q=$db->prepare($query);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function normalizeSearch(array $raw):array {
        $result=[];
        foreach(['q'=>100,'document_type'=>32,'source_type'=>40,'observed_after'=>10,'language_tag'=>16,'issuer'=>100,'reliability'=>20] as $key=>$max){
            $v=$raw[$key]??'';if(!is_string($v))throw new \InvalidArgumentException('Invalid library filter');
            $v=trim($v);if(strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new \InvalidArgumentException('Invalid library filter');
            if($v==='')continue;
            if($key==='document_type'&&!in_array($v,LibraryData::TYPES,true))throw new \InvalidArgumentException('Unknown document kind');
            if($key==='source_type'&&!in_array($v,BusinessData::SOURCE_TYPES,true))throw new \InvalidArgumentException('Unknown source kind');
            if($key==='observed_after')$v=BusinessData::optionalDate($v)??throw new \InvalidArgumentException('Invalid date');
            if($key==='language_tag'&&!preg_match('/\A(?:und|[a-z]{2,3}(?:-[A-Z]{2})?)\z/D',$v))throw new \InvalidArgumentException('Invalid language tag');
            if($key==='reliability'&&!in_array($v,LibraryMetadata::RELIABILITY,true))throw new \InvalidArgumentException('Invalid reliability');
            $result[$key]=$v;
        }
        return $result;
    }
    public static function read(PDO $db,string $id,bool $publicOnly):?array {
        BusinessData::id($id);
        $sql='SELECT d.*,c.display_name AS company_name,c.visibility AS company_visibility,s.publication_state AS source_publication,s.archived_at AS source_archived,s.source_type,s.title AS source_title,v.scan_status,meta.language_tag,meta.issuer,meta.reliability,meta.distribution_permission,meta.permission_checked_on,meta.permission_note FROM library_documents d JOIN companies c ON c.id=d.company_id LEFT JOIN sources s ON s.id=d.source_id LEFT JOIN library_document_metadata meta ON meta.document_id=d.id LEFT JOIN library_document_versions v ON v.document_id=d.id AND v.version_no=d.current_version WHERE d.id=? AND d.archived_at IS NULL';
        $q=$db->prepare($sql);$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        if($publicOnly&&($row['scan_status']??null)!=='clean')return null;
        if($publicOnly&&!LibraryMetadata::canDistribute($row))return null;
        if($publicOnly&&!LibraryData::publicationAllowed($row,['publication_state'=>$row['source_publication'],'archived_at'=>$row['source_archived'],'source_type'=>$row['source_type']],['visibility'=>$row['company_visibility']]))return null;
        return $row;
    }
    public static function version(PDO $db,string $id,int $version):?array {
        BusinessData::id($id);if($version<1)return null;
        $q=$db->prepare('SELECT * FROM library_document_versions WHERE document_id=? AND version_no=?');$q->execute([$id,$version]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function versions(PDO $db,string $id):array {
        BusinessData::id($id);$q=$db->prepare('SELECT version_no,sha256,byte_size,media_type,created_at FROM library_document_versions WHERE document_id=? ORDER BY version_no DESC');$q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function assertPublic(PDO $db,array $data,?array $company,?array $file,string $id):void {
        if(!LibraryMetadata::canDistribute(LibraryMetadata::get($db,$id)))throw new \InvalidArgumentException('Dated redistribution permission required');
        if(($file['scan_status']??null)!=='clean')throw new \InvalidArgumentException('Public documents require an antivirus-clean version');
        $source=null;if($data['source_id']!==null){$q=$db->prepare('SELECT * FROM sources WHERE id=?');$q->execute([$data['source_id']]);$source=$q->fetch(PDO::FETCH_ASSOC)?:null;}
        if(!LibraryData::publicationAllowed($data,$source,$company))throw new \InvalidArgumentException('Public document requires a published company and public authoritative source');
    }
    private static function insertVersion(PDO $db,string $id,int $no,array $file,int $actor):void {
        $q=$db->prepare('INSERT INTO library_document_versions(document_id,version_no,storage_key,original_name,media_type,byte_size,scan_status,sha256,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $q->execute([$id,$no,$file['storage_key'],$file['original_name'],$file['media_type'],$file['byte_size'],$file['scan_status'],$file['sha256'],$actor]);
    }
    private static function event(PDO $db,int $actor,string $id,string $op,?array $before,array $after):void {
        $q=$db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)');
        $q->execute([$actor,'library_documents',$id,$op,$before===null?null:json_encode(['revision'=>$before['revision'],'title'=>$before['title'],'publication_state'=>$before['publication_state']],JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
    }
}
