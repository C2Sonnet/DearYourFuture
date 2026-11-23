<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Server-authorized account management: no new DEVELOPER and no edits to peer/higher admins. */
final class AdminUsersController {
    public static function isRoute(string $path): bool {return $path==='/admin/users'||$path==='/admin/users/new'||(bool)preg_match('~\A/admin/users/[1-9][0-9]{0,17}(?:/(?:resend-invitation|recover-invitation|avatar|avatar-image))?\z~D',$path);}
    public static function mayChange(string $actorRole,int $actorId,string $targetRole,int $targetId,?string $nextRole=null): bool {
        if($actorId===$targetId||!Rbac::canManageAccount($actorRole,$targetRole))return false;
        if($nextRole!==null&&!Rbac::canCreateAccount($actorRole,$nextRole))return false;
        return true;
    }
    public static function normalizeNew(array $raw,string $actorRole): array {
        $handle=trim((string)($raw['handle']??''));$email=strtolower(trim((string)($raw['email']??'')));
        $name=trim((string)($raw['display_name']??''));$role=(string)($raw['role']??'USER');
        if(!preg_match('/\A[a-z][a-z0-9_]{2,39}\z/D',$handle)||ReservedAccounts::reserved($handle)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||$name===''||mbLen($name)>120||!Rbac::canCreateAccount($actorRole,$role)){
            throw new \InvalidArgumentException('Invalid new account or role');
        }
        return ['handle'=>$handle,'email'=>$email,'display_name'=>$name,'role'=>$role];
    }
    public static function handle(string $path,string $method,array $actor):void {
        $role=(string)$actor['role'];$actorId=(int)$actor['id'];
        if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        $db=Database::connected();
        if($path==='/admin/users'){
            if($method!=='GET'){self::error(405,'閲覧以外はフォームから操作してください。');return;}
            $page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*20;
            $stmt=$db->prepare("SELECT u.id,u.handle,u.display_name,u.status,ur.role_code AS role,(SELECT MAX(a.created_at) FROM audit_logs a WHERE a.actor_id=u.id AND a.event_type='account.login') AS last_login_at FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE (?='DEVELOPER' OR ur.role_code<>'DEVELOPER') ORDER BY u.id DESC LIMIT 20 OFFSET ?");
            $stmt->bindValue(1,$role,PDO::PARAM_STR);$stmt->bindValue(2,$offset,PDO::PARAM_INT);$stmt->execute();
            $html='<section class="hero compact"><h1>ユーザー管理</h1><p>本人の応募・メッセージ内容は表示しません。権限に応じたアカウント操作だけを扱います。</p></section><p><a class="btn" href="/admin/users/new">ユーザー追加</a></p><div class="panel"><table><thead><tr><th>アカウント</th><th>役割</th><th>状態</th><th>最終ログイン（DB時刻）</th><th>操作</th></tr></thead><tbody>';
            $displayRows=$stmt->fetchAll(PDO::FETCH_ASSOC);foreach($displayRows as $row){$targetRole=(string)$row['role'];$html.='<tr><td>'.Security::h((string)$row['display_name']).' @'.Security::h((string)$row['handle']).'</td><td>'.Security::h($targetRole).'</td><td>'.Security::h((string)$row['status']).'</td><td>'.Security::h((string)($row['last_login_at']??'記録なし')).'</td><td>'.(self::mayChange($role,$actorId,$targetRole,(int)$row['id'])?'<a href="/admin/users/'.(int)$row['id'].'">管理</a>':'変更不可').'</td></tr>';}
            $html.='</tbody></table></div>';
            if($page>1)$html.='<a class="btn ghost" href="/admin/users?page='.($page-1).'">前へ</a> ';
            if(count($displayRows)===20)$html.='<a class="btn ghost" href="/admin/users?page='.($page+1).'">次へ</a>';
            Kernel::layout('ユーザー管理',$html);return;
        }
        if($path==='/admin/users/new'){
            if($method==='POST'){
                if(!self::csrfAndFresh()){self::error(403,'再ログインして操作してください。');return;}
                try{$fields=self::normalizeNew($_POST,$role);}catch(\Throwable){self::error(422,'入力内容または作成権限を確認してください。');return;}
                $password=(string)($_POST['password']??'');$confirmation=(string)($_POST['password_confirm']??'');
                if(!AccountPasswordService::validNew($password,$confirmation)){self::error(422,'パスワードは8文字以上、英字・数字・記号のうち2種類以上を使用し、確認欄と一致させてください。');return;}
                $passwordHash=password_hash($password,PASSWORD_ARGON2ID);
                unset($password,$confirmation);
                $generatedAvatar=null;
                $commitAttempted=false;
                $db->beginTransaction();
                try{
                    $q=$db->prepare('INSERT INTO users(handle,email,display_name,password_hash,status) VALUES (?,?,?,?,?)');
                    $q->execute([$fields['handle'],$fields['email'],$fields['display_name'],$passwordHash,'active']);
                    $newId=(int)$db->lastInsertId();
                    // Optional image: create a private default PNG from display name / handle.
                    $generatedAvatar=AdminAvatarUpload::hasImage($_FILES['avatar']??null)?AdminAvatarUpload::store($_FILES['avatar']):InitialAvatar::create($fields['handle'],$fields['display_name']);
                    $db->prepare('INSERT INTO user_profiles(user_id,avatar_file) VALUES(?,?)')->execute([$newId,$generatedAvatar]);
                    $db->prepare('INSERT INTO user_roles(user_id,role_code) VALUES(?,?)')->execute([$newId,$fields['role']]);
                    // Newly invited accounts default to notifications ON without altering
                    // the historical message_user_settings CREATE TABLE statement.
                    $db->prepare('INSERT INTO message_user_settings(user_id,allow_direct,public_profile,notify_email) VALUES (?,1,0,1)')->execute([$newId]);
                    WelcomeMessage::enqueue($db,$newId,$fields['display_name']);
                    self::audit($db,$actorId,'account.admin.created',$newId,['role'=>$fields['role']]);
                    $commitAttempted=true;
                    if(!$db->commit())throw new \RuntimeException('Account creation commit failed');
                }catch(\Throwable $e){
                    if($db->inTransaction())try{$db->rollBack();}catch(\Throwable){}
                    // An ambiguous COMMIT may have succeeded; retain its referenced file.
                    if(!$commitAttempted && is_string($generatedAvatar))InitialAvatar::discard($generatedAvatar);
                    error_log('KMM account creation failed: '.get_class($e));
                    self::error(409,'アカウントを作成できません。ID・メール・画像生成環境をご確認ください。');return;
                }
                header('Location: /admin/users?created=1',true,303);return;
            }
            if($method!=='GET'){self::error(405,'GETまたはPOSTのみ');return;}
            $options='';foreach(['USER'=>'一般ユーザー','ADMIN'=>'管理者','FOUNDER'=>'創始者'] as $value=>$label){if(Rbac::canCreateAccount($role,$value))$options.='<option value="'.$value.'">'.$label.'</option>';}
            $html='<section class="hero compact"><h1>ユーザー追加</h1><p>管理者が初期パスワードを直接設定します。メールの本人確認・SMTP送信は行いません。作成後はすぐにログイン可能です。初期パスワードは安全な経路で本人へ伝えてください。アバターは任意の画像を指定できます。未指定なら自動生成します。</p></section>'
                .'<form class="setup-form" data-avatar-editor data-avatar-optional="true" enctype="multipart/form-data" method="post" action="/admin/users/new">'.Session::csrfField()
                .'<label>ユーザーID<input name="handle" pattern="[a-z][a-z0-9_]{2,39}" required autocomplete="off"></label>'
                .'<label>表示名<input name="display_name" maxlength="120" required></label>'
                .'<label>メール（連絡先。新規登録時の認証不要）<input type="email" name="email" maxlength="254" required></label>'
                .'<label>役割<select name="role">'.$options.'</select></label>'
                .'<label>初期パスワード（8文字以上・英字/数字/記号の2種類以上）<input type="password" name="password" minlength="8" maxlength="256" autocomplete="new-password" required></label>'
                .'<label>初期パスワード（確認）<input type="password" name="password_confirm" minlength="8" maxlength="256" autocomplete="new-password" required></label>'
                .AdminAvatarUpload::markup(true).'<button class="btn primary" type="submit">メール認証なしでユーザーを作成</button></form>';
            Kernel::layout('ユーザー追加',$html);return;
        }
        if(preg_match('~\A/admin/users/([1-9][0-9]{0,17})/resend-invitation\z~D',$path,$resend)){
            if($method!=='POST'){self::error(405,'POSTのみ');return;}
            if(!self::csrfAndFresh()){self::error(403,'再ログインが必要です。');return;}
            $targetId=(int)$resend[1];
            if(!MailConfig::configured()){self::error(503,'SMTP設定を確認してください。');return;}
            $q=$db->prepare('SELECT u.id,u.email,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=?');
            $q->execute([$targetId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||!self::mayChange($role,$actorId,(string)$row['role'],$targetId)
                ||!self::invitationRetryEligible((string)$row['status'],self::latestInvitationEvent($db,$targetId))){
                self::error(403,'再送できる招待はありません。');return;
            }
            if(!Throttle::allow('admin-invite-retry:'.$targetId,3,3600)){self::error(429,'再送回数の上限です。');return;}
            // Reserve the retry while holding the user row lock. Other admins cannot
            // initiate an independent delivery while the first attempt is in flight.
            try{self::claimResendInvitation($db,$actorId,$role,$targetId,(string)$row['role'],(string)$row['email']);}
            catch(\Throwable $e){self::error(409,'別の再送処理が進行中、または対象が変更されました。');return;}
            try{$sent=TokenService::issue($db,['id'=>$targetId,'email'=>(string)$row['email']],'reset_password');}
            catch(\Throwable $e){error_log('KMM invite resend failed: '.get_class($e));$sent=false;}
            if(!$sent){
                try{self::failResendInvitation($db,$actorId,$role,$targetId,(string)$row['role'],(string)$row['email']);}
                catch(\Throwable $e){error_log('KMM invite resend cleanup needs operator review: '.get_class($e));self::error(503,'再送失敗後の状態を確定できません。運営者の確認が必要です。');return;}
                self::error(503,'再送に失敗しました。リンクを失効し、無効状態を維持しています。');return;
            }
            try{self::activateResentInvitation($db,$actorId,$role,$targetId,(string)$row['role'],(string)$row['email']);}
            catch(\Throwable $e){error_log('KMM invite resend activation failed: '.get_class($e));self::error(503,'メール配送後の状態確認に失敗しました。アカウントは運営者の確認待ちです。');return;}
            header('Location: /admin/users?invited=1',true,303);return;
        }
        // A delivery-unknown invitation requires an explicit, recently authenticated
        // FOUNDER/DEVELOPER review. This does NOT send mail or enable the account.
        if(preg_match('~\A/admin/users/([1-9][0-9]{0,17})/recover-invitation\z~D',$path,$recover)){
            if($method!=='POST'){self::error(405,'POSTのみ');return;}
            if(!self::csrfAndFresh()){self::error(403,'再ログインが必要です。');return;}
            if(!in_array($role,['FOUNDER','DEVELOPER'],true)){self::error(403,'復旧承認権限がありません。');return;}
            $userId=(int)$recover[1];
            $q=$db->prepare('SELECT u.id,u.email,u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=?');
            $q->execute([$userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||!self::mayChange($role,$actorId,(string)$row['role'],$userId)){
                self::error(403,'対象の確認権限がありません。');return;
            }
            try{self::prepareUncertainInvitationRetry($db,$actorId,$role,$userId,(string)$row['role'],
                (string)$row['email'],(string)($_POST['evidence_ref']??''),
                (string)($_POST['revoke_old_links']??'')==='yes');}
            catch(\Throwable $e){error_log('KMM invitation recovery blocked: '.get_class($e));self::error(409,'監査状態・権限・確認番号を再確認してください。復旧は行っていません。');return;}
            header('Location: /admin/users/'.$userId.'?recovery=prepared',true,303);return;
        }
        if(preg_match('~\A/admin/users/([1-9][0-9]{0,17})/(avatar|avatar-image)\z~D',$path,$avatarMatch)){
            $targetId=(int)$avatarMatch[1];$mode=$avatarMatch[2];
            $query=$db->prepare('SELECT u.id,ur.role_code AS role,p.avatar_file FROM users u JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=?');
            $query->execute([$targetId]);$targetAvatar=$query->fetch(PDO::FETCH_ASSOC);
            if(!$targetAvatar||!self::mayChange($role,$actorId,(string)$targetAvatar['role'],$targetId)){self::error(403,'このアカウントの画像を変更できません');return;}
            if($mode==='avatar-image'){
                if($method!=='GET'){http_response_code(405);return;}
                $filename=$targetAvatar['avatar_file']??null;
                if(!is_string($filename)||!preg_match('/\A[a-f0-9]{32}\.(png|jpg|webp)\z/D',$filename,$matches)){http_response_code(404);return;}
                $file=SiteState::root().'/storage/private/avatars/'.$filename;
                if(!is_file($file)||is_link($file)){http_response_code(404);return;}
                header('Content-Type: '.($matches[1]==='png'?'image/png':($matches[1]==='jpg'?'image/jpeg':'image/webp')));
                header('Cache-Control: private, no-store');readfile($file);return;
            }
            if($method!=='POST'){header('Allow: POST');self::error(405,'POSTのみ');return;}
            if(!self::csrfAndFresh()){self::error(403,'再ログインして操作してください');return;}
            if(!AdminAvatarUpload::hasImage($_FILES['avatar']??null)){self::error(422,'画像を選択してください');return;}
            try{$newAvatar=AdminAvatarUpload::store($_FILES['avatar']);}
            catch(\Throwable $e){error_log('KMM admin avatar rejected: '.get_class($e));self::error(422,'JPEG/PNG・画像の縦横と容量を確認してください');return;}
            $committing=false;
            try{
                $db->beginTransaction();
                $lock=$db->prepare('SELECT ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');$lock->execute([$targetId]);$latest=$lock->fetch(PDO::FETCH_ASSOC);
                if(!$latest||!self::mayChange($role,$actorId,(string)$latest['role'],$targetId))throw new \DomainException('User permission changed');
                $db->prepare('INSERT INTO user_profiles(user_id,avatar_file) VALUES (?,?) ON DUPLICATE KEY UPDATE avatar_file=VALUES(avatar_file)')->execute([$targetId,$newAvatar]);
                self::audit($db,$actorId,'account.admin.avatar_changed',$targetId,[]);
                $committing=true;if(!$db->commit())throw new \RuntimeException('Avatar commit failed');
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();if(!$committing)AvatarFileStore::discard(SiteState::root().'/storage/private/avatars',$newAvatar);self::error(409,'アバターを保存できませんでした');return;}
            $prior=$targetAvatar['avatar_file']??null;
            if(is_string($prior)&&$prior!==$newAvatar)AvatarFileStore::discard(SiteState::root().'/storage/private/avatars',$prior);
            header('Location: /admin/users/'.$targetId.'?avatar=updated',true,303);return;
        }
        if(!preg_match('~\A/admin/users/([1-9][0-9]{0,17})\z~D',$path,$m)){self::error(404,'ページがありません');return;}
        $targetId=(int)$m[1];
        $q=$db->prepare("SELECT u.id,u.handle,u.display_name,u.status,ur.role_code AS role,(SELECT MAX(a.created_at) FROM audit_logs a WHERE a.actor_id=u.id AND a.event_type='account.login') AS last_login_at FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=?");$q->execute([$targetId]);$target=$q->fetch(PDO::FETCH_ASSOC);
        if(!$target||($role!=='DEVELOPER'&&($target['role']??'')==='DEVELOPER')||!self::mayChange($role,$actorId,(string)$target['role'],$targetId)){self::error(403,'このアカウントを管理する権限がありません。');return;}
        if($method==='POST'){
            if(!self::csrfAndFresh()){self::error(403,'再ログインが必要です。');return;}
            $newRole=(string)($_POST['role']??'');$state=(string)($_POST['status']??'');
            if(!in_array($state,['active','disabled'],true)||!self::mayChange($role,$actorId,(string)$target['role'],$targetId,$newRole)){self::error(403,'変更権限または入力が無効です。');return;}
            try{self::applyManagement($db,$actorId,$role,$targetId,(string)$target['role'],(string)$target['status'],$newRole,$state);}
            catch(\Throwable $e){self::error(409,'競合、または変更できないアカウントです。');return;}
            header('Location: /admin/users?updated=1',true,303);return;
        }
        if($method!=='GET'){self::error(405,'GETまたはPOSTのみ');return;}
        $options='';foreach(['USER'=>'一般ユーザー','ADMIN'=>'管理者','FOUNDER'=>'創始者'] as $value=>$label){if(Rbac::canCreateAccount($role,$value))$options.='<option value="'.$value.'"'.($target['role']===$value?' selected':'').'>'.$label.'</option>';}
        $active=$target['status']==='active';
        $invitationEvent=$active?null:self::latestInvitationEvent($db,$targetId);
        $pendingInvitation=self::invitationActivationBlocked((string)$target['status'],$invitationEvent);
        // Do not offer a generic activation option for an unverified/ambiguous invite.
        // The POST handler independently enforces the same rule under a row lock.
        $statusOptions=$pendingInvitation?'<option value="disabled" selected>無効（招待確認待ち）</option>':'<option value="active"'.($active?' selected':'').'>有効</option><option value="disabled"'.(!$active?' selected':'').'>無効</option>';
        $warning=$pendingInvitation?'<p role="status">招待メールの状態を確認する必要があります。一般的なアカウント変更から有効化できません。送信済みか不明の場合は監査記録と配送記録を運営者が照合してください。</p>':'';
        $html='<section class="hero compact"><h1>アカウント管理</h1><p>'.Security::h((string)$target['display_name']).' @'.Security::h((string)$target['handle']).'</p><p>最終ログイン：'.Security::h((string)($target['last_login_at']??'記録なし')).'</p></section>'.$warning.'<form class="setup-form" method="post" action="'.$path.'">'.Session::csrfField().'<label>役割<select name="role">'.$options.'</select></label><label>状態<select name="status">'.$statusOptions.'</select></label><p>権限・状態を変更すると対象ユーザーのログイン状態と未使用トークンを失効させます。</p><button class="btn primary">変更を保存</button></form>';
        $html.='<form class="setup-form avatar-form" data-avatar-editor data-avatar-optional="true" action="/admin/users/'.$targetId.'/avatar" method="post" enctype="multipart/form-data">'.Session::csrfField().'<h2>アバターを変更</h2><img src="/admin/users/'.$targetId.'/avatar-image" class="avatar-picture" alt="現在のアバター" loading="lazy">'.AdminAvatarUpload::markup(true).'<button type="submit" class="btn primary">アバターを保存</button></form>';
        if(self::invitationRetryEligible((string)$target['status'],$invitationEvent)){
            $html.='<form method="post" action="/admin/users/'.$targetId.'/resend-invitation" class="setup-form">'.Session::csrfField().'<p>以前の招待メールが失敗したため、このユーザーは無効です。本人の登録メールへ新しいリンクを送信し、配送成功後に有効化します。</p><button type="submit" class="btn ghost">招待メールを再送</button></form>';
        }
        // The review step is deliberately separate from the actual resend.
        if($pendingInvitation && $invitationEvent==='account.admin.invitation_retry_started'
            && in_array($role,['FOUNDER','DEVELOPER'],true)){
            $html.='<form method="post" action="/admin/users/'.$targetId.'/recover-invitation" class="setup-form">'
                .Session::csrfField().'<p>前回の招待配送結果が不明です。SMTP記録・監査履歴・本人への案内を別経路で照合したうえで、既存の未使用リンクをすべて失効させ、再送準備のみ行います。自動有効化は行いません。</p>'
                .'<label>運営確認番号<input name="evidence_ref" required minlength="8" maxlength="64" pattern="[A-Z0-9][A-Z0-9_-]{7,63}"></label>'
                .'<label><input type="checkbox" name="revoke_old_links" value="yes" required>配送不明と既存リンク失効の影響を確認しました</label>'
                .'<button type="submit" class="btn ghost">監査を記録し再送可能にする</button></form>';
        }
        Kernel::layout('アカウント管理',$html);
    }
    /** Atomic, stale-form-safe role and account-state change.
     * The role, auth_version, pending auth tokens and audit are a single unit.
     * Next HTTP request rejects any existing session with the old auth_version.
     */
    public static function applyManagement(PDO $db,int $actorId,string $actorRole,int $targetId,
        string $expectedRole,string $expectedStatus,string $newRole,string $newStatus):void {
        if($actorId<1||$targetId<1||!in_array($newStatus,['active','disabled'],true)
            ||!self::mayChange($actorRole,$actorId,$expectedRole,$targetId,$newRole)){
            throw new \DomainException('Account management forbidden');
        }
        $db->beginTransaction();
        try{
            $lock=$db->prepare('SELECT u.status,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $lock->execute([$targetId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$current||!self::mayChange($actorRole,$actorId,(string)$current['role'],$targetId,$newRole)
                ||$current['status']!==$expectedStatus||$current['role']!==$expectedRole){
                throw new \RuntimeException('Account state has changed; reload');
            }
            // A stale/general account-management form must never activate a pending
            // invitation, even when the initial invite failed or SMTP succeeded but
            // its subsequent DB commit failed. Keep the review requirement durable.
            if($newStatus==='active' && $expectedStatus==='disabled' &&
                self::invitationActivationBlocked((string)$current['status'],self::latestInvitationEvent($db,$targetId))){
                throw new \DomainException('Invitation needs verified recovery; ordinary activation forbidden');
            }
            $q=$db->prepare('UPDATE user_roles SET role_code=? WHERE user_id=? AND role_code=?');
            $q->execute([$newRole,$targetId,$expectedRole]);
            // MySQL returns 0 changed rows for an unchanged role, which is legitimate.
            $q=$db->prepare('UPDATE users SET status=?,auth_version=auth_version+1 WHERE id=? AND status=?');
            $q->execute([$newStatus,$targetId,$expectedStatus]);
            if($q->rowCount()!==1)throw new \RuntimeException('Account update raced');
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')
                ->execute([$targetId]);
            self::audit($db,$actorId,'account.admin.managed',$targetId,[
                'before_role'=>$expectedRole,'after_role'=>$newRole,
                'before_status'=>$expectedStatus,'after_status'=>$newStatus,
            ]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Disable and invalidate every pending token atomically after failed mail delivery.
     * The account remains disabled until an authorized administrator reviews it.
     * Never expose an unverified invitation as an active login.
     */
    public static function disableFailedInvitation(PDO $db,int $userId,int $actorId):void {
        if($userId<1||$actorId<1)throw new \InvalidArgumentException('Invalid invitation actor/target');
        $db->beginTransaction();
        try {
            $q=$db->prepare("UPDATE users SET status='disabled',auth_version=auth_version+1 WHERE id=? AND status='active'");
            $q->execute([$userId]);
            if($q->rowCount()!==1)throw new \RuntimeException('Invitation target is not active');
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')->execute([$userId]);
            self::audit($db,$actorId,'account.admin.invitation_failed',$userId,[]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Only previously failed invitations can be retried; ordinary disabled users are excluded. */
    public static function invitationRetryEligible(string $status,?string $lastEvent):bool {
        return $status==='disabled' && in_array($lastEvent,[
            'account.admin.invitation_failed',
            'account.admin.invitation_recovery_cleared',
        ],true);
    }
    /** Explicit fail-closed policy shared by UI and transaction-time authorization.
     * SMTP success after an audit/DB commit failure remains in retry_started.
     * This method does not assert successful delivery and never activates anyone.
     */
    public static function invitationActivationBlocked(string $status,?string $lastEvent):bool {
        return $status==='disabled' && in_array($lastEvent,[
            'account.admin.invitation_failed',
            'account.admin.invitation_retry_started',
            'account.admin.invitation_recovery_cleared',
        ],true);
    }
    private static function latestInvitationEvent(PDO $db,int $id):?string {
        $q=$db->prepare("SELECT event_type FROM audit_logs WHERE target_type='user' AND target_key=? AND event_type IN ('account.admin.invitation_failed','account.admin.invitation_retry_started','account.admin.invitation_resent','account.admin.invitation_recovery_cleared') ORDER BY id DESC LIMIT 1");
        $q->execute([(string)$id]);$event=$q->fetchColumn();return is_string($event)?$event:null;
    }
    /** Single-flight reservation: no network I/O inside an open SQL transaction. */
    public static function claimResendInvitation(PDO $db,int $actorId,string $actorRole,int $userId,
        string $expectedRole,string $expectedEmail):void {
        self::resendTransition($db,$actorId,$actorRole,$userId,$expectedRole,$expectedEmail,
            ['account.admin.invitation_failed','account.admin.invitation_recovery_cleared'],
            'account.admin.invitation_retry_started',false);
    }
    /** A failed SMTP attempt reopens retry eligibility only after revoking any pending link. */
    public static function failResendInvitation(PDO $db,int $actorId,string $actorRole,int $userId,
        string $expectedRole,string $expectedEmail):void {
        self::resendTransition($db,$actorId,$actorRole,$userId,$expectedRole,$expectedEmail,
            'account.admin.invitation_retry_started','account.admin.invitation_failed',true);
    }
    private static function resendTransition(PDO $db,int $actorId,string $actorRole,int $userId,
        string $expectedRole,string $expectedEmail,string|array $from,string $to,bool $revoke):void {
        if($actorId<1||$userId<1||!filter_var($expectedEmail,FILTER_VALIDATE_EMAIL)
            ||!self::mayChange($actorRole,$actorId,$expectedRole,$userId))throw new \DomainException('Invalid invitation transition');
        if($db->inTransaction())throw new \RuntimeException('Invitation transition requires transaction ownership');
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT u.status,u.email,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
            if(!$user||$user['status']!=='disabled'||$user['role']!==$expectedRole||$user['email']!==$expectedEmail
                ||!self::mayChange($actorRole,$actorId,(string)$user['role'],$userId)
                ||!in_array(self::latestInvitationEvent($db,$userId),(array)$from,true))throw new \RuntimeException('Invitation retry is already claimed, stale or forbidden');
            if($revoke)$db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')
                ->execute([$userId]);
            self::audit($db,$actorId,$to,$userId,[]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Acknowledged recovery of an *uncertain* SMTP outcome. This does not
     * infer mail delivery, grant login, or send anything. All unconsumed
     * authentication tokens are revoked before another retry can be claimed.
     * The evidence reference is a ticket identifier only (never mailbox content).
     */
    public static function prepareUncertainInvitationRetry(PDO $db,int $actorId,string $actorRole,int $userId,
        string $expectedRole,string $expectedEmail,string $evidenceRef,bool $acknowledged):void {
        if(!$acknowledged||!in_array($actorRole,['FOUNDER','DEVELOPER'],true)
            ||!preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/D',$evidenceRef)
            ||$actorId<1||$userId<1||!filter_var($expectedEmail,FILTER_VALIDATE_EMAIL)
            ||!self::mayChange($actorRole,$actorId,$expectedRole,$userId)){
            throw new \DomainException('Operator review, evidence and distinct authorization required');
        }
        if($db->inTransaction())throw new \RuntimeException('Recovery requires a standalone transaction');
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT u.status,u.email,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
            if(!$user||$user['status']!=='disabled'||$user['role']!==$expectedRole
                ||$user['email']!==$expectedEmail
                ||!self::mayChange($actorRole,$actorId,(string)$user['role'],$userId)
                ||self::latestInvitationEvent($db,$userId)!=='account.admin.invitation_retry_started'){
                throw new \RuntimeException('Uncertain invitation state changed; no automatic recovery');
            }
            $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')
                ->execute([$userId]);
            self::audit($db,$actorId,'account.admin.invitation_recovery_cleared',$userId,[
                'evidence_ref'=>$evidenceRef,
                'operator_acknowledged_unknown_delivery'=>true,
                'old_links_revoked'=>true,
                'account_activated'=>false,
            ]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Called only after SMTP success. Atomically marks the user active and audits resending. */
    public static function activateResentInvitation(PDO $db,int $actorId,string $actorRole,int $userId,
        string $expectedRole,string $expectedEmail):void {
        if($actorId<1||$userId<1||!self::mayChange($actorRole,$actorId,$expectedRole,$userId))throw new \DomainException('Invalid invitation retry');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT u.status,u.email,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
            if(!$user||$user['role']!==$expectedRole||$user['email']!==$expectedEmail
                ||!self::mayChange($actorRole,$actorId,(string)$user['role'],$userId)
                ||$user['status']!=='disabled'||self::latestInvitationEvent($db,$userId)!=='account.admin.invitation_retry_started'){
                throw new \RuntimeException('Invitation state changed; operator review required');
            }
            $q=$db->prepare("UPDATE users SET status='active',auth_version=auth_version+1 WHERE id=? AND status='disabled' AND email=?");
            $q->execute([$userId,$expectedEmail]);if($q->rowCount()!==1)throw new \RuntimeException('Concurrent account reactivation');
            self::audit($db,$actorId,'account.admin.invitation_resent',$userId,[]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function csrfAndFresh():bool{return Session::validCsrf((string)($_POST['csrf']??''))&& (int)($_SESSION['authenticated_at']??0)>=time()-900;}
    private static function audit(PDO $db,int $actor,string $event,int $target,array $details):void {
        $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$actor,$event,'user',(string)$target,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    private static function error(int $status,string $message):void {http_response_code($status);Kernel::layout('ユーザー管理', '<section class="hero compact"><h1>ユーザー管理</h1><p>'.Security::h($message).'</p></section>',false);}
}
/** Safely count Unicode characters even without mbstring. */
function mbLen(string $text): int {return function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);}
