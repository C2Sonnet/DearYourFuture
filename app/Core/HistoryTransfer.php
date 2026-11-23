<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** History portability is append-only. Imported history never rewrites audit, user, or business tables. */
final class HistoryTransfer {
    public const HEADERS=['source_event_id','record_type','operation','summary','source_occurred_at'];
    /** Preview consent is one-use, actor-scoped, digest-scoped and short-lived. */
    public static function previewPermitted(mixed $preview,string $digest,int $actor,int $now):bool {
        if(!is_array($preview)||$actor<1||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))return false;
        $stored=$preview['digest']??null;
        if(!is_string($stored)||!preg_match('/\A[a-f0-9]{64}\z/D',$stored))return false;
        return (int)($preview['actor']??0)===$actor
            && is_int($preview['actor']??null)
            && is_int($preview['until']??null)
            && $preview['until']>=$now
            && $preview['until']<=$now+600
            && hash_equals($stored,$digest);
    }
    public static function format(string $format):string {
        if(!in_array($format,['jsonl','csv'],true))throw new \InvalidArgumentException('Unsupported export format');
        return $format;
    }
    public static function spreadsheetSafe(string $value):string {
        // Formula injection also occurs when CSV data starts with control whitespace.
        return preg_match('/\A[\x00-\x20]*[=+@\-]/u',$value)?"'".$value:$value;
    }
    public static function export(PDO $db,string $format,int $limit=500):string {
        self::format($format);if($limit<1||$limit>1000)throw new \InvalidArgumentException('Export maximum 1000');
        $rows=$db->query('SELECT id,record_type,operation,created_at FROM business_change_events ORDER BY id DESC LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
        $normalized=[];
        foreach($rows as $event){
            // No sensitive before/after values, personal notes, actor identifiers or credentials.
            $normalized[]=[
                'source_event_id'=>(string)$event['id'],
                'record_type'=>(string)$event['record_type'],
                'operation'=>(string)$event['operation'],
                'summary'=>'業務データの変更（機密値を除外）',
                'source_occurred_at'=>(string)$event['created_at'],
            ];
        }
        if($format==='jsonl')return implode('',array_map(static fn($row)=>SeedData::encode($row)."\n",$normalized));
        $stream=fopen('php://temp','w+');if($stream===false)throw new \RuntimeException('Stream unavailable');
        fputcsv($stream,self::HEADERS,',','"','');
        foreach($normalized as $row){$safe=array_map(static fn($x)=>self::spreadsheetSafe((string)$x),array_values($row));fputcsv($stream,$safe,',','"','');}
        rewind($stream);$result=stream_get_contents($stream);fclose($stream);
        if(!is_string($result))throw new \RuntimeException('Export failed');return $result;
    }
    public static function preview(string $bytes,string $format):array {
        self::format($format);
        if(strlen($bytes)>512*1024||$bytes===''||!preg_match('//u',$bytes))throw new \InvalidArgumentException('Import empty, invalid UTF-8, or too large');
        $rows=[];
        if($format==='jsonl'){
            foreach(explode("\n",str_replace("\r\n","\n",$bytes)) as $line){if(trim($line)==='')continue;
                $record=json_decode($line,true,64,JSON_THROW_ON_ERROR);
                if(!is_array($record))throw new \InvalidArgumentException('Invalid JSONL item');$rows[]=self::normalize($record);
            }
        }else{
            $fp=fopen('php://temp','w+');if($fp===false)throw new \RuntimeException('No temp stream');fwrite($fp,$bytes);rewind($fp);
            $head=fgetcsv($fp,0,',','"','');
            if($head!==self::HEADERS){fclose($fp);throw new \InvalidArgumentException('CSV columns do not match');}
            while(($parts=fgetcsv($fp,0,',','"',''))!==false){
                if($parts===[null])continue;
                if(count($parts)!==count(self::HEADERS)){fclose($fp);throw new \InvalidArgumentException('CSV item has wrong number of columns');}
                $rows[]=self::normalize(array_combine(self::HEADERS,$parts));
            }fclose($fp);
        }
        if(count($rows)<1||count($rows)>1000)throw new \InvalidArgumentException('Import must contain 1–1000 records');
        return $rows;
    }
    private static function normalize(array $record):array {
        if(count($record)!==count(self::HEADERS)||array_diff(self::HEADERS,array_keys($record)))throw new \InvalidArgumentException('Import shape not allowed');
        $id=BusinessData::text($record['source_event_id'],80);
        $kind=BusinessData::text($record['record_type'],40,true);
        $op=BusinessData::text($record['operation'],30,true);
        $summary=BusinessData::text($record['summary'],240,true);
        $when=$record['source_occurred_at'];
        if(!is_string($when)||!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',$when))throw new \InvalidArgumentException('Invalid history timestamp');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$when,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$when)throw new \InvalidArgumentException('Invalid calendar history timestamp');
        return ['source_event_id'=>$id,'record_type'=>$kind,'operation'=>$op,'summary'=>$summary,'source_occurred_at'=>$when];
    }
    public static function append(PDO $db,string $bytes,string $format,int $actorId):array {
        $records=self::preview($bytes,$format);if($actorId<1)throw new \InvalidArgumentException('Authentication required');
        $digest=hash('sha256',$format."\n".$bytes);$batch=BusinessData::id();
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id FROM history_import_batches WHERE digest=?');$q->execute([$digest]);
            $existing=$q->fetchColumn();if($existing!==false){$db->commit();return ['status'=>'duplicate','count'=>0,'id'=>(string)$existing];}
            $db->prepare('INSERT INTO history_import_batches(id,digest,format,row_count,operator_id) VALUES (?,?,?,?,?)')->execute([$batch,$digest,$format,count($records),$actorId]);
            $q=$db->prepare('INSERT INTO history_import_items(batch_id,source_event_id,record_type,operation,summary,source_occurred_at) VALUES (?,?,?,?,?,?)');
            foreach($records as $r)$q->execute([$batch,...array_values($r)]);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actorId,'history.appended','history_import_batch',$batch,SeedData::encode(['count'=>count($records),'format'=>$format])]);
            $db->commit();return ['status'=>'imported','count'=>count($records),'id'=>$batch];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
