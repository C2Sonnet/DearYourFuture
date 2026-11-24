<?php
declare(strict_types=1);
namespace KMM\Core;
final class EligibilityController {
    public static function isRoute(string $path):bool {return preg_match('~\A/jobs/[a-f0-9]{32}/checklist\z~D',$path)===1;}
    public static function handle(string $path,string $method):void {
        if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}
        $user=Identity::current();
        if($user===null){header('Location: /account/login',true,303);return;}
        if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);Kernel::layout('確認が必要です','<p>安全確認ができませんでした。</p>');return;}
        if(!Throttle::allow('eligibility:'.(int)$user['id'],30,900)){http_response_code(429);return;}
        $jobId=substr($path,6,32);
        try{
            EligibilityRepository::save(Database::connected(),(int)$user['id'],$jobId,$_POST['checked']??[]);
            header('Location: /jobs/'.$jobId.'?checks=saved',true,303);
        }catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('チェック項目を確認','<p>入力された項目を保存できませんでした。</p>');}
    }
}
