<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class Identity {
    private static ?array $current=null;
    private static bool $checked=false;
    public static function current(): ?array {
        if(self::$checked)return self::$current;
        self::$checked=true;
        $uid=(int)($_SESSION['uid']??0);
        if($uid<1)return null;
        $q=Database::connected()->prepare("SELECT u.id,u.handle,u.email,u.display_name,u.auth_version,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? LIMIT 1");
        $q->execute([$uid]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if(!$user||$user['status']!=='active'||(int)$user['auth_version']!==(int)($_SESSION['auth_version']??-1)){
            Session::clearIdentity();return null;
        }
        return self::$current=$user;
    }
    public static function signIn(array $user): void {
        session_regenerate_id(true);
        $_SESSION['uid']=(int)$user['id'];$_SESSION['auth_version']=(int)$user['auth_version'];
        $_SESSION['authenticated_at']=time();unset($_SESSION['csrf'],$_SESSION['history_preview'],$_SESSION['reviewed_seed_preview'],$_SESSION['reviewed_seed_cancelled'],$_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates']);
        self::$checked=true;self::$current=$user;
    }
    public static function signOut(): void {Session::clearIdentity();self::$checked=true;self::$current=null;}
}
