<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
try {
    $result=\KMM\Core\ReminderRepository::deliver(\KMM\Core\Database::connected());
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Reminder delivery failed: '.get_class($e)."\n");exit(1);}
