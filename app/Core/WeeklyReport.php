<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Site messages only; cron invokes this after installation. No schema replacement. */
final class WeeklyReport {
    public const CONFIG='weekly_report.config.v1';
    private const STATE='weekly_report.state.v1';
    private const PENDING='weekly_report.research_changes.v1';
    public const TEMPLATE="{period}\n{counts}\n{changes}\n掲載情報は実際の応募資格・残枠を保証しません。";
    public static function get(PDO $db,string $key,mixed $default):mixed {
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:json_decode((string)$v,true,128,JSON_THROW_ON_ERROR);
    }
    public static function put(PDO $db,string $key,mixed $value):void {
        $db->prepare('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()')->execute([$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    public static function defaults():array {return ['enabled'=>true,'weekday'=>5,'time'=>'09:00','frequency'=>1,'subject'=>'企業・求人の週報','template'=>self::TEMPLATE];}
    public static function config(PDO $db):array {return self::validate(self::get($db,self::CONFIG,self::defaults()));}
    public static function validate(array $v):array {
        if(!is_bool($v['enabled']??null)||!is_int($v['weekday']??null)||$v['weekday']<1||$v['weekday']>7||!is_int($v['frequency']??null)||$v['frequency']<1||$v['frequency']>12||!is_string($v['time']??null)||!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$v['time']))throw new \InvalidArgumentException('曜日・時刻・頻度を確認してください');
        $v['subject']=MessageData::text($v['subject']??'',65,true);$v['template']=MessageData::text($v['template']??'',1500,true);
        foreach(['{period}','{counts}','{changes}'] as $required)if(substr_count($v['template'],$required)!==1)throw new \InvalidArgumentException('本文に期間・件数・変更一覧の各変数を一度ずつ含めてください');
        return $v;
    }
    public static function nextDue(\DateTimeImmutable $after,array $config,bool $following=false):\DateTimeImmutable {
        $after=$after->setTimezone(new \DateTimeZone('Asia/Tokyo'));[$h,$m]=array_map('intval',explode(':',$config['time']));
        if($following)return $after->modify('+'.$config['frequency'].' weeks')->setTime($h,$m);
        $delta=($config['weekday']-(int)$after->format('N')+7)%7;$next=$after->modify('+'.$delta.' days')->setTime($h,$m);
        if($next<=$after)$next=$next->modify('+7 days');return $next;
    }
    private static function maxEvent(PDO $db):int {return (int)$db->query("SELECT COALESCE(MAX(id),0) FROM business_change_events")->fetchColumn();}
    public static function initialize(PDO $db,\DateTimeImmutable $now):array {
        $s=self::get($db,self::STATE,null);if($s!==null)return $s;
        $s=['cursor'=>self::maxEvent($db),'since'=>$now->format(DATE_ATOM),'next_due'=>self::nextDue($now,self::config($db))->format(DATE_ATOM)];self::put($db,self::STATE,$s);return $s;
    }
    /** Called inside the update's existing transaction before commit, so no research changes are lost between reports. */
    public static function recordRelease(PDO $db,?array $old,?array $new,string $version):void {
        if(!$db->inTransaction())throw new \RuntimeException('Weekly release journal requires transaction');
        self::initialize($db,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')));
        if(!$old||!$new)return;
        $events=self::get($db,self::PENDING,[]);foreach(self::diff($old,$new) as $e){$e['reason']='v'.$version.'：'.$e['reason'];$events[]=$e;}
        self::put($db,self::PENDING,$events);
    }
    private static function classification(array $data,string $kind):array {
        $out=[];foreach(($data['r07_batch65_handover'][$kind]??[]) as $v)foreach(explode(';',(string)$v[$kind==='companies'?'raw_company_ids':'raw_job_ids']) as $id)$out[$id]=$v;
        if($kind==='jobs')foreach(($data['r07_batch66_comparison']['comparison']??[]) as $v)foreach(explode(';',(string)$v['raw_job_ids']) as $id)$out[$id]['comparison']=$v;
        return $out;
    }
    public static function diff(array $old,array $new):array {
        $out=[];
        foreach(['companies'=>'company_id','jobs'=>'job_id'] as $kind=>$key){
            $a=array_column($old[$kind]??[],null,$key);$b=array_column($new[$kind]??[],null,$key);$ca=self::classification($old,$kind);$cb=self::classification($new,$kind);
            foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $id){
                $before=$a[$id]??null;$after=$b[$id]??null;
                if($before===$after&&($ca[$id]??null)===($cb[$id]??null))continue;
                $op=$before===null?'add':($after===null?'delete':'update');$item=$after??$before;
                $reasons=[];if($op==='add')$reasons[]='採用データ集に新規収録';elseif($op==='delete')$reasons[]='現行収録から除外（旧記録は保全）';else{
                    if($before!==$after)$reasons[]='公開原典・確認情報の記録更新';
                    if(($ca[$id]??null)!==($cb[$id]??null))$reasons[]='専門技術・応募候補分類または比較資料の更新';
                }
                $name=(string)($kind==='companies'?($item['legal_name']??$item['brand']??$id):(($item['brand']??'').' '.$item['job_title']));
                $out[]=['kind'=>$kind,'id'=>$id,'op'=>$op,'name'=>$name,'reason'=>implode('、',$reasons),'path'=>$op==='delete'?'/'.$kind:'/'.$kind.'/research/'.$id];
            }
        }return $out;
    }
    private static function publicEvents(PDO $db,int $after,int $through):array {
        $q=$db->prepare("SELECT * FROM business_change_events WHERE id>? AND id<=? AND record_type IN ('companies','jobs') ORDER BY id FOR UPDATE");$q->execute([$after,$through]);$out=[];
        while($e=$q->fetch(PDO::FETCH_ASSOC)){
            $kind=$e['record_type'];$id=$e['record_id'];$a=json_decode((string)($e['before_json']??''),true);$b=json_decode((string)($e['after_json']??''),true);
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))continue;
            $before=is_array($a)?$a:[];$after=is_array($b)?array_replace($before,$b):$before;
            // Exclude the private R06 seed, never publish private fields or audit actors.
            $qSeed=$db->prepare("SELECT 1 FROM seed_entity_states WHERE record_type=? AND record_id=? AND seed_version='research-d1.0.4-R06'");$qSeed->execute([$kind,$id]);if($qSeed->fetchColumn())continue;
            $archived=($b['archived']??false)===true;
            if($kind==='companies'){
                $was=($before['visibility']??'')==='published';$is=!$archived&&($after['visibility']??'')==='published';
            }else{
                $coId=$after['company_id']??null;if(!is_string($coId)||!BusinessRepository::read($db,'companies',$coId,true))continue;
                $was=$before!==[]&&($before['archived_at']??null)===null;$is=!$archived&&$after!==[]&&($after['archived_at']??null)===null;
            }
            if(!$was&&!$is)continue;$op=!$was?'add':(!$is?'delete':'update');
            // For deletion use only the old public name; do not link to a removed record.
            $item=$op==='delete'?$before:$after;$name=(string)($kind==='companies'?($item['display_name']??'企業'):($item['title']??'求人'));
            $labels=['display_name'=>'企業名','legal_name'=>'法人名','description'=>'概要','website_url'=>'公式サイト','title'=>'求人名','category'=>'職種','graduate_year'=>'募集年度','recruitment_state'=>'募集状態','deadline_on'=>'掲載期限','source_id'=>'出典','note'=>'補足','visibility'=>'掲載状態'];$fields=[];foreach($labels as $k=>$label)if(($before[$k]??null)!==($after[$k]??null))$fields[]=$label;
            $reason=match($op){'add'=>'公開対象に追加','delete'=>'公開対象から除外','update'=>$fields?'変更項目：'.implode('、',$fields):'登録情報更新'};
            $out[]=['kind'=>$kind,'id'=>$id,'op'=>$op,'name'=>$name,'reason'=>$reason.'（詳細な変更理由は未記録）','path'=>'/'.$kind.($op==='delete'?'':'/'.$id)];
        }return $out;
    }
    /** Chunk at complete lines; every message has a valid internal link and no inline HTML. */
    public static function sections(array $events):array {
        $counts=[];$lines=[];$unique=[];
        foreach($events as $e){$key=$e['kind'].'/'.$e['id'].'/'.$e['op'];if(isset($unique[$key])){$unique[$key]['reason']=implode('／',array_unique([$unique[$key]['reason'],$e['reason']]));}else $unique[$key]=$e;}
        foreach(['companies'=>'企業','jobs'=>'求人'] as $kind=>$label)foreach(['add'=>'新規','update'=>'変更','delete'=>'削除'] as $op=>$action){$items=array_filter($unique,static fn($e)=>$e['kind']===$kind&&$e['op']===$op);$counts[]=$label.' '.$action.'：'.count($items).'件';foreach($items as $e){$lines[]=$label.' '.$action.'：'.$e['name']."\n理由：".$e['reason']."\n確認先：".$e['path'];}}
        $chunks=[];$current='';foreach($lines as $line){if(strlen($current.$line)>57000&&$current!==''){$chunks[]=$current;$current='';}$current.=($current===''?'':"\n\n").$line;}if($current!=='')$chunks[]=$current;if(!$chunks)$chunks=['新規・変更・削除の記録はありません。'];
        return ['counts'=>implode("\n",$counts),'chunks'=>$chunks];
    }
    public static function run(PDO $db,\DateTimeImmutable $now):array {
        if((int)$db->query("SELECT GET_LOCK('kmm_weekly_report_v1',0)")->fetchColumn()!==1)return ['status'=>'busy'];
        try {
            $db->beginTransaction();$config=self::config($db);$state=self::initialize($db,$now);$due=new \DateTimeImmutable($state['next_due']);
            if(!$config['enabled']||$now<$due){$db->commit();return ['status'=>$config['enabled']?'not_due':'disabled','next_due'=>$state['next_due']];}
            $through=(int)$db->query('SELECT id FROM business_change_events ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();$events=array_merge(self::get($db,self::PENDING,[]),self::publicEvents($db,(int)$state['cursor'],$through));$parts=self::sections($events);
            $dbCompanies=(int)$db->query("SELECT COUNT(*) FROM companies WHERE visibility<>'archived'")->fetchColumn();$dbJobs=(int)$db->query("SELECT COUNT(*) FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.archived_at IS NULL AND c.visibility<>'archived'")->fetchColumn();
            $counts='DB登録（非アーカイブ）：企業 '.$dbCompanies.'件・求人 '.$dbJobs."件\n".'サイト採用データ集：企業 '.count(ResearchR07::companies()).'件・求人 '.count(ResearchR07::jobs())."件（原ID単位）\n".$parts['counts'];
            $period='対象期間：'.(new \DateTimeImmutable($state['since']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i').' ～ '.$now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i').'（日本時間）';
            $insert=$db->prepare("INSERT INTO messages(kind,sender_user_id,subject,body,system_event_key) VALUES ('system',NULL,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $users=$db->query("SELECT id FROM users WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);$rec=$db->prepare('INSERT IGNORE INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)');
            foreach($parts['chunks'] as $i=>$chunk){$body=strtr($config['template'],['{period}'=>$period,'{counts}'=>$counts,'{changes}'=>$chunk]);if(strlen($body)>65000)throw new \RuntimeException('Weekly report exceeds safe message size');$key='weekly:'.$due->format('YmdHi').':'.($i+1);$subject=$config['subject'].(count($parts['chunks'])>1?'（'.($i+1).'/'.count($parts['chunks']).'）':'');$insert->execute([$subject,$body,$key]);$mid=(int)$db->lastInsertId();foreach($users as $uid)$rec->execute([$mid,$uid]);}
            // Catch up once (all changes since last report), not one duplicate message per missed week.
            do{$due=self::nextDue($due,$config,true);}while($due<=$now);
            self::put($db,self::PENDING,[]);self::put($db,self::STATE,['cursor'=>$through,'since'=>$now->format(DATE_ATOM),'next_due'=>$due->format(DATE_ATOM)]);$db->commit();return ['status'=>'sent','messages'=>count($parts['chunks']),'recipients'=>count($users),'next_due'=>$due->format(DATE_ATOM)];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{$db->query("SELECT RELEASE_LOCK('kmm_weekly_report_v1')");}
    }
    public static function save(PDO $db,array $config,int $actor):void {
        $config=self::validate($config);if((int)$db->query("SELECT GET_LOCK('kmm_weekly_report_v1',0)")->fetchColumn()!==1)throw new \RuntimeException('週報処理中です');
        try{$db->beginTransaction();$now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo'));$previous=self::config($db);$state=self::initialize($db,$now);
            if(array_intersect_key($previous,array_flip(['weekday','time','frequency','enabled']))!==array_intersect_key($config,array_flip(['weekday','time','frequency','enabled'])))$state['next_due']=self::nextDue($now,$config)->format(DATE_ATOM);
            self::put($db,self::CONFIG,$config);self::put($db,self::STATE,$state);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actor,'weekly_report.settings.changed','setting',self::CONFIG,'{}']);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{$db->query("SELECT RELEASE_LOCK('kmm_weekly_report_v1')");}
    }
}
