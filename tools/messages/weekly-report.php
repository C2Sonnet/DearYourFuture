<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{Database,SiteState,WeeklyReport};
try{
    if(SiteState::state()!=='ready'){echo "Site not ready; skipped.\n";exit(0);}
    $db=Database::connected();
    if(in_array('--plan',$argv,true)){echo json_encode(['config'=>WeeklyReport::config($db),'state'=>WeeklyReport::get($db,'weekly_report.state.v1',null),'will_send'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";exit(0);}
    if(!in_array('--run',$argv,true)){echo "Use --plan or --run.\n";exit(0);}
    echo json_encode(WeeklyReport::run($db,new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo'))),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Weekly report failed: '.get_class($e)."\n");exit(1);}
