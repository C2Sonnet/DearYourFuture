<?php
declare(strict_types=1);
// Cron-capable optional dispatch of published company/job event notifications.
// First run only initializes a cursor to the latest event; older history is NOT sent.
// Never run this script from the web server or on an unreviewed production release.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\Database;
use KMM\Core\MailNotifications;

// Read-only operator preview: no cursor initialization, sends, or other writes.
if(in_array('--plan',$argv,true)){
    try{
        $db=Database::connected();
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? LIMIT 1');
        $q->execute(['mail.dispatch.public_cursor.v1']);$raw=$q->fetchColumn();
        $cursor=$raw===false?null:json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if($cursor!==null&&(!is_int($cursor)||$cursor<0))throw new RuntimeException('Invalid notification cursor');
        $q=$db->prepare("SELECT COUNT(*) FROM public_change_events WHERE id>? AND record_type IN ('companies','jobs') AND operation IN ('create','update')");
        $q->execute([$cursor??0]);$pending=(int)$q->fetchColumn();
        $recipients=(int)$db->query("SELECT COUNT(*) FROM users u JOIN message_user_settings s ON s.user_id=u.id WHERE u.status='active' AND s.notify_email=1")->fetchColumn();
        echo json_encode([
            'mode'=>'read_only_plan',
            'cursor_initialized'=>$cursor!==null,
            'first_send_initializes_cursor_without_historical_mail'=>$cursor===null,
            'candidate_public_events_since_cursor'=>$pending,
            'eligible_global_opt_in_users_before_category_opt_out'=>$recipients,
            'smtp_configured'=>\KMM\Core\MailConfig::configured(),
            'max_recipients_per_event'=>300,
            'will_send_anything'=>false,
            'no_database_or_file_mutations'=>true,
        ],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable $e){fwrite(STDERR,'Read-only mail plan unavailable: '.get_class($e)."\n");exit(1);}
    exit(0);
}
if(!in_array('--send',$argv,true)){
    echo "No messages sent. Use --plan for a read-only overview; --send explicitly initializes or dispatches subsequent public changes.\n";
    exit(0);
}
try{
    $db=Database::connected();
    if((int)$db->query("SELECT GET_LOCK('kmm_pubmail_v1',0)")->fetchColumn()!==1){fwrite(STDERR,"Worker already running\n");exit(1);}
    try{
        $cursorKey='mail.dispatch.public_cursor.v1';
        $q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');$q->execute([$cursorKey]);
        $raw=$q->fetchColumn();
        if($raw===false){
            $max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM public_change_events')->fetchColumn();
            $db->prepare('INSERT IGNORE INTO system_settings(setting_key,value_json) VALUES(?,?)')->execute([$cursorKey,json_encode($max,JSON_THROW_ON_ERROR)]);
            echo "Initialized cursor at current public event ID $max. No historical mail sent.\n";exit(0);
        }
        $cursor=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_int($cursor)||$cursor<0)throw new RuntimeException('Invalid cursor');
        $q=$db->prepare("SELECT p.id,p.company_id,p.record_type,p.operation,p.summary,c.display_name FROM public_change_events p JOIN companies c ON c.id=p.company_id WHERE p.id>? AND p.record_type IN ('companies','jobs') AND p.operation IN ('create','update') ORDER BY p.id ASC LIMIT 10");
        $q->execute([$cursor]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
        $sent=0;$attempted=0;
        foreach($events as $event){
            $eventId=(int)$event['id'];$kind=$event['operation']==='create'?'research_added':'research_updated';
            $q=$db->prepare("SELECT u.id FROM users u JOIN message_user_settings s ON s.user_id=u.id WHERE u.status='active' AND s.notify_email=1 ORDER BY u.id LIMIT 301");
            $q->execute();$users=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($users)>300)throw new RuntimeException('Too many recipients, operator review required');
            $url=rtrim((string)Database::settings($db,'site.url'),'/').'/companies/'.$event['company_id'];
            foreach($users as $uid){
                $id=(int)$uid;
                if($id<1)continue;
                $key='mail.dispatch.attempt.v1.'.$eventId.'.'.$id;
                $claim=$db->prepare('INSERT IGNORE INTO system_settings(setting_key,value_json) VALUES(?,?)');
                $claim->execute([$key,'"claimed"']);
                if($claim->rowCount()===0)continue; // at-most-once attempts on retries
                $attempted++;
                $ok=MailNotifications::notify($db,$id,$kind,['url'=>$url,'detail'=>(string)$event['display_name'].'：'.(string)$event['summary']]);
                if($ok)$sent++;
                $db->prepare('UPDATE system_settings SET value_json=?,updated_at=UTC_TIMESTAMP() WHERE setting_key=?')->execute([json_encode($ok?'sent':'not-sent',JSON_THROW_ON_ERROR),$key]);
            }
            $db->prepare('UPDATE system_settings SET value_json=?,updated_at=UTC_TIMESTAMP() WHERE setting_key=?')->execute([json_encode($eventId,JSON_THROW_ON_ERROR),$cursorKey]);
        }
        echo json_encode(['processed_events'=>count($events),'attempted'=>$attempted,'smtp_accepted'=>$sent],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    } finally{$db->query("SELECT RELEASE_LOCK('kmm_pubmail_v1')");}
}catch(Throwable $e){fwrite(STDERR,'Notification worker failed: '.get_class($e)."\n");exit(1);}
