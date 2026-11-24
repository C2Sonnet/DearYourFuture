<?php
declare(strict_types=1);
/** Round26/Batch33 explicit staging-only DB bridge; never run on a live site. */
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\Database;
use KMM\Core\ResearchR07DbBridge;

$actions=['--plan','--preflight','--apply','--verify'];
$asked=array_values(array_intersect($actions,$argv));
if(count($asked)!==1){fwrite(STDERR,"Usage: php tools/research/r07-db-bridge.php --plan|--preflight|--verify|--apply [guard flags]\n");exit(2);}
$action=$asked[0];
try{
    if($action==='--plan')$report=ResearchR07DbBridge::sourcePlan();
    else{
        $db=Database::connected();
        if($action==='--preflight')$report=ResearchR07DbBridge::preflight($db);
        elseif($action==='--verify')$report=ResearchR07DbBridge::verify($db);
        else{
            $dbName=(string)$db->query('SELECT DATABASE()')->fetchColumn();
            $match=false;$backup=false;$ack=false;
            foreach($argv as $arg){
                if(str_starts_with($arg,'--db='))$match=hash_equals($dbName,substr($arg,5));
                if(preg_match('/\A--backup-sha256=[a-fA-F0-9]{64}\z/D',$arg))$backup=true;
                if($arg==='--ack=ISOLATED_STAGING_ONLY')$ack=true;
            }
            if(getenv('KMM_R07_MIGRATION_ENV')!=='staging'||!$match||!$backup||!$ack)
                throw new \RuntimeException('Staging-only import requires matching DB, backup SHA, environment marker, and explicit acknowledgment');
            $report=ResearchR07DbBridge::apply($db);
        }
    }
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
}catch(\Throwable $e){
    // No SQL values, personally identifying details, credentials or tokens printed.
    fwrite(STDERR,"R07 bridge refused: ".get_class($e)."; verify source, isolated DB, backup and preflight.\n");
    exit(1);
}
