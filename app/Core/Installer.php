<?php
declare(strict_types=1);
namespace KMM\Core;
final class Installer {
    private static function tokenPath(): string {return SiteState::root().'/storage/system/setup-token.json';}
    public static function validateToken(string $token): bool {
        if(!preg_match('/\A[a-f0-9]{64}\z/',$token))return false;
        $raw=@file_get_contents(self::tokenPath());$data=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($data)||($data['expires']??0)<time()||!is_string($data['hash']??null))return false;
        return hash_equals($data['hash'],hash('sha256',$token));
    }
    /** Consume the CLI setup token exactly once. Never store the token itself. */
    private static function consumeToken(string $token): bool {
        if(!preg_match('/\A[a-f0-9]{64}\z/',$token))return false;
        $path=self::tokenPath();
        if(!is_file($path))return false;
        $handle=@fopen($path,'r+');
        if($handle===false)return false;
        try {
            if(!flock($handle,LOCK_EX))return false;
            $raw=stream_get_contents($handle);
            $record=is_string($raw)?json_decode($raw,true):null;
            if(!is_array($record)||!is_int($record['expires']??null)
                || $record['expires']<=time()||!is_string($record['hash']??null)
                || !hash_equals($record['hash'],hash('sha256',$token)))return false;
            rewind($handle);
            if(!ftruncate($handle,0)||!fflush($handle))throw new \RuntimeException('Cannot consume setup token');
            if(!unlink($path))throw new \RuntimeException('Cannot remove consumed setup token');
            return true;
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
    /** The one-time CLI token grants a short, server-side setup session only. */
    private static function setupAuthorized(): bool {
        $authorized=$_SESSION['setup_authorized']??false;
        $issued=$_SESSION['setup_authorized_at']??null;
        $now=time();
        return $authorized===true && is_int($issued)
            && $issued>=$now-1800 && $issued<=$now;
    }
    public static function handle(string $path,string $method): void {
        if(SiteState::state()!=='new') { self::render('インストールはロックされています','設置済み／復旧待ちの状態です。既存データを守るため再インストールできません。',503);return; }
        if(!Session::isHttps()&&!(PHP_SAPI === 'cli-server' && Session::isLoopback())) {self::render('HTTPSが必要です','インストーラはHTTPSでのみ開けます。',503);return;}
        if($method==='GET' && ($path==='/initial'||$path==='/initial/environment')){
            if(self::setupAuthorized()) {self::form();return;}
            self::activation();return;
        }
        if($method==='POST' && $path==='/initial/activate'){
            if(!Session::validCsrf(self::postedString('csrf')??'')){self::render('認証エラー','画面を更新してお試しください。',403);return;}
            $remote=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            if(!Throttle::allow('setup:'.$remote,5,900)){self::render('操作制限','しばらくしてからお試しください。',429);return;}
            if(!self::consumeToken(self::postedString('setup_token')??'')){self::render('セットアップトークンが違います','サーバー管理者から発行された一回限りのトークンをご確認ください。',403);return;}
            session_regenerate_id(true);$_SESSION['setup_authorized']=true;$_SESSION['setup_authorized_at']=time();
            header('Location: /initial',true,303);return;
        }
        if($method==='POST' && $path==='/initial/install'){
            if(!self::setupAuthorized()||!Session::validCsrf(self::postedString('csrf')??'')){self::render('設置権限がありません','新しいセットアップトークンを発行してください。',403);return;}
            self::install();return;
        }
        self::render('ページがありません','利用できる初期設定の操作ではありません。',404);
    }
    /** Reject array-valued and other unexpected HTTP form fields before casting. */
    private static function postedString(string $key): ?string {
        $value=$_POST[$key]??null;
        return is_string($value)?$value:null;
    }
    private static function activation(): void {
        $html='<section class="hero compact"><span class="eyebrow">INITIAL · v'.Security::h(SiteState::displayVersion()).'</span><h1>サイトの初期設定</h1><p>初回の開発者を作るため、サーバー側で発行した一回限りのセットアップトークンを入力してください。</p></section>';
        $html.='<form method="post" action="/initial/activate" class="setup-form">'.Session::csrfField().'<label>セットアップトークン<input name="setup_token" type="password" minlength="64" maxlength="64" required autocomplete="off"></label><button class="btn primary">認証して進む</button></form>';
        $html.='<aside class="note">トークンはサーバーCLIの tools/install/create-token.php で発行します。トークンなしでは登録できません。</aside>';
        Kernel::layout('初期設定',$html,false);
    }
    private static function form(string $error=''): void {
        $checks=[
            'PHP '.PHP_VERSION => version_compare(PHP_VERSION,'8.4','>='),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'Argon2id' => defined('PASSWORD_ARGON2ID'),
            'HTTPS/localhost' => Session::isHttps()||Session::isLoopback(),
        ];
        $html='<section class="hero compact"><span class="eyebrow">DEVELOPER FIRST · v'.Security::h(SiteState::displayVersion()).'</span><h1>初期開発者とMySQLの設定</h1><p>開発者情報はDBテーブルを作成する前に入力し、Migration後に正式保存します。</p></section>';
        if($error!=='')$html.='<p role="alert" class="note">'.Security::h($error).'</p>';
        foreach($checks as $title=>$ok)$html.='<div class="check"><span>'.Security::h($title).'</span><strong>'.($ok?'OK':'未対応').'</strong></div>';
        $html.='<form method="post" action="/initial/install" class="setup-form">'.Session::csrfField();
        $html.='<fieldset><legend>① サイト基本情報</legend><label>サイト名<input name="site_name" value="キミの未来へ" required maxlength="100"></label><label>サイトURL（HTTPS）<input name="site_url" placeholder="https://example.jp" required></label></fieldset>';
        $html.='<fieldset><legend>② MySQL接続（テーブル作成前に接続試験）</legend><label>MySQLホスト<input name="db_host" value="localhost" required></label><label>ポート<input type="number" name="db_port" value="3306" min="1" max="65535" required></label><label>データベース名<input name="db_name" required></label><label>DBユーザー<input name="db_user" required></label><label>DBパスワード<input name="db_pass" type="password" autocomplete="new-password"></label></fieldset>';
        $html.='<fieldset><legend>③ 必須：初期DEVELOPER</legend><label>ユーザーID<input name="dev_handle" required minlength="3" maxlength="40" autocomplete="off"></label><label>表示名<input name="dev_name" required maxlength="120"></label><label>メール<input type="email" name="dev_email" required maxlength="254"></label><label>パスワード<input type="password" name="dev_pass" required minlength="8" autocomplete="new-password"></label><label>パスワード確認<input type="password" name="dev_pass_confirm" required minlength="8" autocomplete="new-password"></label></fieldset>';
        $html.='<aside class="note">初期アバターは登録した表示名またはユーザーIDから自動生成されます。画像のアップロードは不要で、本人の画像に変更すると初期画像は削除されます。</aside>';
        $html.='<aside class="note"><strong>新規設置専用：</strong>選択するMySQLデータベースには、テーブルやビューが一つも存在しない必要があります。旧設置DBを使う場合は復旧手順を利用し、初期設置を再実行しないでください。</aside>';
        $html.='<aside class="note">初回設置では、旧Seed10・調査27・リンク59を保全し、研究d1.0.4 R06の正規化された企業103件・求人128件を同じトランザクションで非公開導入します。明示終了8件と掲載状態矛盾1件は求人DBに導入しません。募集継続・個別応募可能とは断定しません。</aside>';
        $html.='<aside class="note">初期設定：共通パスワードOFF／未ログイン閲覧OFF／パスワードリセットON／自己登録OFF。SMTPは開発者のログイン後に設定します。</aside><button class="btn primary">確認してサイトを設置する</button></form>';
        Kernel::layout('初期設定',$html,false);
    }
    private static function install(): void {
        if(!extension_loaded('pdo_mysql')||!defined('PASSWORD_ARGON2ID')){self::form('必要なPHP拡張が不足しています。データは変更していません。');return;}
        // Enforce byte limits before expensive password hashing or DB connections.
        // A password may contain punctuation; it must still have a bounded size.
        $limits=['site_name'=>300,'site_url'=>2048,'dev_handle'=>40,'dev_email'=>254,
            'dev_name'=>360,'dev_pass'=>4096,'dev_pass_confirm'=>4096,
            'db_host'=>253,'db_port'=>5,'db_name'=>64,'db_user'=>128,'db_pass'=>4096];
        foreach($limits as $key=>$maxBytes) {
            $value=self::postedString($key);
            if($value===null || strlen($value)>$maxBytes){
                self::form('入力の形式または長さが正しくありません。');return;
            }
        }
        $portText=self::postedString('db_port');
        if(!preg_match('/\A[0-9]{1,5}\z/D',$portText??'') || (int)$portText<1 || (int)$portText>65535) {
            self::form('MySQLポートの指定が正しくありません。');return;
        }
        $site=trim((string)($_POST['site_name']??''));$url=trim((string)($_POST['site_url']??''));
        $handle=trim((string)($_POST['dev_handle']??''));$email=trim((string)($_POST['dev_email']??''));$name=trim((string)($_POST['dev_name']??''));
        $password=(string)($_POST['dev_pass']??'');
        if($site===''||strlen($site)>300||!preg_match('/\Ahttps:\/\/[a-z0-9][a-z0-9.:-]*(?:\/[a-z0-9_\/-]*)?\z/i',$url)||!preg_match('/\A[A-Za-z0-9_-]{3,40}\z/',$handle)||ReservedAccounts::reserved($handle)||!filter_var($email,FILTER_VALIDATE_EMAIL)||$name===''||strlen($name)>360||!PasswordPolicy::valid($password)||$password!==(string)($_POST['dev_pass_confirm']??'')){
            self::form('サイトURL、ID、メールまたはパスワードをご確認ください。');return;
        }
        $conf=['host'=>trim((string)($_POST['db_host']??'')),'port'=>(int)$portText,'database'=>trim((string)($_POST['db_name']??'')),'username'=>(string)($_POST['db_user']??''),'password'=>(string)($_POST['db_pass']??'')];
        $dir=SiteState::root().'/storage/system';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){self::render('保存不可','サイトの非公開ストレージがありません。',503);return;}
        $lock=fopen($dir.'/installer.mutex','c');if($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){self::render('インストール中','別のインストールが進行中です。',423);return;}
        $stage='initial_state';
        try {
            if(SiteState::state()!=='new')throw new \RuntimeException('Recovery required');
            if(!is_writable(SiteState::root()))throw new \RuntimeException('Site root must be writable for install.lock and update.lock before any DB changes');
            // Check the entire source-pinned seed corpus before DB/DDL, config or intent writes.
            // A packaged release missing private docs must fail safely with zero DB changes.
            $stage='package_integrity_preflight';
            $package=InstallPackageIntegrity::inspect();
            if(!$package['ok']){
                error_log('KMM install preflight rejected: package_missing='.count($package['missing'])
                    .' package_mismatch='.count($package['mismatch']).' package_invalid='.count($package['invalid']));
                self::form('配布パッケージに初期データの検証用ファイルが不足、または不一致です。DBと設置設定は変更していません。正しい配布ZIPを管理者が確認してください。');
                return;
            }
            $stage='db_connection';
            $db=Database::open($conf); // Test connectivity before schema creation.
            // Check BEFORE install intent, database.json, and any DDL/seed writes.
            // Existing v0.219.12 records must never be treated as a new install.
            $stage='empty_database_preflight';
            $existingObjects=FreshInstallPreflight::countDatabaseObjects($db);
            if($existingObjects!==0){
                error_log('KMM install preflight rejected: nonempty_database object_count='.$existingObjects);
                self::form('選択されたデータベースには既存のテーブルまたはビューがあります。既存データは変更していません。必ず別の空の新規データベースを指定してください。');
                return;
            }
            $stage='password_hash';
            $hash=password_hash($password,PASSWORD_ARGON2ID);
            if(!is_string($hash))throw new \RuntimeException('Cannot hash password');
            // The intent is written before the first persistent DB/config change.
            // It is deliberately retained if the process crashes or a DDL step fails.
            $stage='create_install_intent';
            SiteState::markInitialInstallStarted();
            $stage='save_db_config';
            Database::writeConfig($conf);
            $stage='create_schema';
            Migration::apply($db);
            $id=bin2hex(random_bytes(16));
            $stage='seed_database';
            Migration::seed($db,$id,['handle'=>$handle,'email'=>mb_strtolower_safe($email),'name'=>$name,'hash'=>$hash],AccessPolicy::DEFAULTS+['site.name'=>$site,'site.url'=>$url]);
            $stage='write_signed_locks';
            SiteState::createLocks($id);
            $stage='finalize_install_intent';
            SiteState::clearInitialInstallIntent();
            $stage='verify_ready_state';
            if(SiteState::state()!=='ready')throw new \RuntimeException('Installation verification failed');
            @unlink(self::tokenPath());unset($_SESSION['setup_authorized'],$_SESSION['setup_authorized_at']);
            session_regenerate_id(true);
            header('Location: /account/login?installed=1',true,303);return;
        } catch(\Throwable $e){
            // Log safe diagnostic metadata only; never credentials or SQL contents.
            $errno=$e instanceof \PDOException && is_array($e->errorInfo??null)
                ? (int)($e->errorInfo[1]??0) : 0;
            error_log('KMM install refused stage='.$stage.' type='.get_class($e).' mysql_errno='.$errno);
            self::render('安全のため設置を停止しました','停止工程: '.str_replace('_','-',$stage).'。DB・権限・設定の整合性を管理者が確認してください。部分的な設置がある場合は既存データを消さずに復旧する必要があります。',503);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function render(string $title,string $body,int $status): void {http_response_code($status);Kernel::layout($title,'<section class="hero compact"><h1>'.Security::h($title).'</h1><p>'.Security::h($body).'</p></section>',false);}
}
/** Lowercasing ASCII in email addresses is not always safe; leave local part intact. */
function mb_strtolower_safe(string $email): string {return $email;}
