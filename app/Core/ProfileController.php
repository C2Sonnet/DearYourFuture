<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class ProfileController {
    public static function isRoute(string $path): bool {return in_array($path,['/account/center','/account/profile','/account/security','/account/avatar','/account/notifications','/account/verify-email'],true);}
    public static function handle(string $path,string $method): void {
        if($path==='/account/verify-email'){self::verifyEmail($method);return;}
        $user=Identity::current();if(!$user){
            $next=Session::safeReturn((string)($_SERVER['REQUEST_URI']??'/account/center'));
            header('Location: /account/login?next='.rawurlencode($next),true,302);return;
        }
        if($path==='/account/avatar'){self::avatar($user,$method);return;}
        if($path==='/account/notifications'){self::notifications($user,$method);return;}
        if($path==='/account/center'){self::center($user,$method);return;}
        if($path==='/account/profile'){self::profile($user,$method);return;}
        if($path==='/account/security'){self::security($user,$method);return;}
        self::view('ページがありません','<p>このページはありません。</p>',404);
    }
    private static function notifications(array $user,string $method):void {
        $db=Database::connected();$uid=(int)$user['id'];
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::view('認証エラー','<p>CSRFが無効です。</p>',403);return;}
            if(!Throttle::allow('mail-notification-settings:'.$uid,12,900)){self::view('操作制限','<p>時間をおいてからお試しください。</p>',429);return;}
            if((string)($_POST['action']??'')==='resend_verification'){
                $q=$db->prepare('SELECT email FROM users WHERE id=? AND status=\'active\'');$q->execute([$uid]);$email=$q->fetchColumn();
                if(!is_string($email)){self::view('認証エラー','<p>再ログインしてください。</p>',401);return;}
                if(EmailVerificationState::status($db,$uid,$email)==='verified'){header('Location: /account/notifications?verified=1',true,303);return;}
                $sent=TokenService::issue($db,['id'=>$uid,'email'=>$email],'email_verify');
                header('Location: /account/notifications?verify_sent='.($sent?'1':'0'),true,303);return;
            }
            try{
                $prefs=[];foreach(MailNotifications::DEFAULTS as $id=>$_)$prefs[$id]=($_POST['notice_'.$id]??'')==='1';
                MailNotifications::savePreferences($db,$uid,$prefs);
                header('Location: /account/notifications?saved=1',true,303);return;
            }catch(\Throwable $e){error_log('KMM mail preferences unavailable: '.get_class($e));self::view('通知設定を保存できません','<p>設定を確認してください。</p>',503);return;}
        }
        if($method!=='GET'){self::view('許可されない操作','<p>GETまたはPOSTで操作してください。</p>',405);return;}
        try{$prefs=MailNotifications::preferences($db,$uid);$base=MessageRepository::settings($db,$uid);}
        catch(\Throwable){self::view('通知設定を読み込めません','<p>管理者にご連絡ください。</p>',503);return;}
        $enabled=(int)($base['notify_email']??0)===1;
        $q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$uid]);$currentEmail=(string)$q->fetchColumn();
        $verification=EmailVerificationState::status($db,$uid,$currentEmail);
        $html=self::head('メール通知の設定','現在のメールアドレスの確認状況と、通知メールの配信設定を管理できます。');
        $html.='<section class="panel email-verification-panel"><h2>メールアドレスの確認状態</h2><p>登録アドレス：<strong>'.Security::h($currentEmail).'</strong></p>';
        if($verification==='verified')$html.='<p role="status" class="verification-verified">確認済み</p>';
        else {
            $html.='<p role="status" class="verification-unverified">'.($verification==='unrecorded'?'検証記録なし（旧登録メール）':'未検証').'</p>';
            $html.='<p class="note">未検証でもログインとパスワード再設定には利用できます。メールが届かない場合はSMTP設定をご確認ください。</p>';
            $html.='<form action="/account/notifications" method="post">'.Session::csrfField().'<input type="hidden" name="action" value="resend_verification"><button class="btn ghost" type="submit">認証メールを再発信</button></form>';
        }
        if(isset($_GET['verify_sent']))$html.='<p role="status" class="note">'.($_GET['verify_sent']==='1'?'検証メールの発信を受け付けました。':'検証メールの送信に失敗しました。管理者へご連絡ください。').'</p>';
        $html.='</section>';
        if(isset($_GET['saved']))$html.='<p class="note" role="status">メール通知設定を保存しました。</p>';
        if(!$enabled)$html.='<p class="note">全体のメール通知が現在OFFです。下記の項目をONにしても配信されません。<a href="/messages/settings">メッセージ設定から全体のメール通知をONにする</a></p>';
        $html.='<form class="setup-form" action="/account/notifications" method="post">'.Session::csrfField();
        foreach(MailNotifications::DEFAULTS as $id=>$t)$html.='<label class="preference-checkbox"><input type="checkbox" name="notice_'.Security::h($id).'" value="1"'.(!empty($prefs[$id])?' checked':'').'><span>'.Security::h($t['name']).'</span></label>';
        $html.='<button class="btn primary">配信設定を保存</button></form><p><a class="btn ghost" href="/account/center">ユーザーセンターへ戻る</a></p>';
        self::view('メール通知の設定',$html);
    }
    private static function view(string $title,string $html,int $status=200): void {http_response_code($status);Kernel::layout($title,$html,false);}
    private static function head(string $title,string $desc): string {return '<section class="hero compact"><span class="eyebrow">MY ACCOUNT</span><h1>'.Security::h($title).'</h1><p>'.Security::h($desc).'</p></section>';}
    private static function center(array $user,string $method):void {
        // Keep the legacy account route consistent with the actual private application center.
        ApplicationController::handle('/account/center',$method);
    }
    private static function profile(array $user,string $method): void {
        $db=Database::connected();$notice='';$id=(int)$user['id'];
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::view('操作エラー','<p>CSRFを確認できません。</p>',403);return;}
            if(!Throttle::allow('profile:'.$id,12,900)){self::view('操作制限','<p>少し時間をおいてください。</p>',429);return;}
            $display=trim((string)($_POST['display_name']??''));$handle=trim((string)($_POST['handle']??''));$mail=trim((string)($_POST['email']??''));
            if(strlen($display)<1||strlen($display)>120||!preg_match('/\A[A-Za-z0-9_-]{3,40}\z/',$handle)||ReservedAccounts::reserved($handle)||strlen($mail)>254||!filter_var($mail,FILTER_VALIDATE_EMAIL)){
                self::view('入力エラー','<p>表示名・ID・メールを確認してください。</p>',422);return;
            }
            try{
                $db->beginTransaction();
                $q=$db->prepare("SELECT email,password_hash FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row){$db->rollBack();self::view('認証エラー','<p>再ログインしてください。</p>',401);return;}
                $oldEmail=(string)$row['email'];$changed=strcasecmp($mail,$oldEmail)!==0;
                // A local current-password check protects account takeover; no old-email message is sent.
                if($changed && !password_verify((string)($_POST['current_password_for_email']??''),(string)$row['password_hash'])){
                    $db->rollBack();self::view('現在のパスワードを確認','<p>メール変更には現在のパスワードが必要です。旧アドレスへの確認メールは送信しません。</p>',422);return;
                }
                $q=$db->prepare('UPDATE users SET display_name=?,handle=?,email=?'.($changed?',auth_version=auth_version+1':'').' WHERE id=? AND status=\'active\'');
                $q->execute([$display,$handle,$mail,$id]);
                if($changed){
                    EmailVerificationState::record($db,$id,$mail,false);
                    // Reset links for the previous address must never remain usable.
                    $db->prepare('UPDATE auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL')->execute([$id]);
                }
                $event=$changed?'account.email.changed_unverified':'account.profile.updated';
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(?,?,?,?,?)')->execute([$id,$event,'user',(string)$id,'{}']);
                $db->commit();
                if($changed){Identity::signOut();header('Location: /account/login?email_changed=1',true,303);return;}
                $notice='プロフィールを更新しました。';
                $user['display_name']=$display;$user['handle']=$handle;$user['email']=$mail;
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();error_log('KMM profile update error: '.get_class($e));self::view('保存できません','<p>メールアドレスやユーザーIDの重複、保存設定を確認してください。</p>',422);return;}
        }elseif($method!=='GET'){self::view('許可されない操作','<p>この操作はできません。</p>',405);return;}
        $html=self::head('プロフィール','アバター・お名前・公開ID・メールアドレスを変更できます。メール変更は旧メールの認証なしで即時反映され、新しいメールは確認まで未検証です。');
        if($notice!=='')$html.='<p class="note" role="status">'.Security::h($notice).'</p>';
        $html.='<p><a class="btn ghost" href="/account/center">← 前のページに戻る</a></p>';
        $html.='<form class="setup-form" action="/account/profile" method="post">'.Session::csrfField().
            '<label>表示名<input name="display_name" required maxlength="120" value="'.Security::h((string)$user['display_name']).'"></label>'.
            '<label>ユーザーID（公開表示用）<input name="handle" pattern="[A-Za-z0-9_-]{3,40}" required maxlength="40" value="'.Security::h((string)$user['handle']).'"></label>'.
            '<label>メールアドレス<input name="email" type="email" required maxlength="254" value="'.Security::h((string)$user['email']).'"></label>'.
            '<label>メールアドレス変更時の現在のパスワード（メール変更時のみ必須）<input name="current_password_for_email" type="password" autocomplete="current-password"></label>'.
            '<p class="note">変更後は安全のため一度ログアウトします。新しいメールは未検証でもログイン・パスワード再設定に利用できます。検証メールはユーザーセンターで発信できます。</p>'.
            '<button class="btn primary" type="submit">プロフィールを保存する</button></form>';
        $html.='<form class="setup-form avatar-form" data-avatar-editor action="/account/avatar" method="post" enctype="multipart/form-data">'.Session::csrfField().'<label>元画像（JPEG / PNG、最大2MB）<input type="file" name="avatar" accept="image/jpeg,image/png" required></label><p class="note">アバター画像は登録時に自動生成されるため、アップロードは任意です。自分の画像を登録すると初期画像を削除して置き換えます。画像を選び、ドラッグで位置を調整してください。正方形に切り抜いて512px角のJPEGまたはPNGで保存します。JPEGは容量を小さく、PNGは画質を優先します。</p><div class="avatar-crop-ui" hidden><canvas width="512" height="512" tabindex="0" role="img" aria-label="アバターの正方形プレビュー。矢印キーで移動、Shiftキーと矢印キーで大きく移動、Homeキーでリセット"></canvas><div class="avatar-crop-controls"><label>保存形式<select data-avatar-format name="avatar_format"><option value="image/jpeg" selected>JPEG（JPG・容量を節約）</option><option value="image/png">PNG（高画質）</option></select></label><label>拡大縮小<input type="range" data-avatar-zoom min="1" max="3" step="0.05" value="1"></label><button type="button" class="btn ghost" data-avatar-reset>リセット</button></div></div><p class="avatar-editor-error" role="alert" aria-live="assertive"></p><p class="avatar-editor-status" data-avatar-status role="status" aria-live="polite"></p><button class="btn primary" type="submit">確定してアップロード</button></form>';
        $html.='<p><a class="text-link" href="/account/security">パスワードなどの安全設定 →</a></p>';
        self::view('プロフィール',$html);
    }
    private static function avatar(array $user,string $method): void {
        $db=Database::connected();$id=(int)$user['id'];
        $q=$db->prepare('SELECT avatar_file FROM user_profiles WHERE user_id=?');$q->execute([$id]);$name=$q->fetchColumn();
        $dir=SiteState::root().'/storage/private/avatars';
        if($method==='GET'){
            if(!is_string($name)||!preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/',$name)){http_response_code(404);return;}
            $file=$dir.'/'.$name;if(!is_file($file)){http_response_code(404);return;}
            $ext=pathinfo($file,PATHINFO_EXTENSION);header('Content-Type: '.['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][$ext]);header('Content-Length: '.filesize($file));readfile($file);return;
        }
        if($method!=='POST'||!Session::validCsrf((string)($_POST['csrf']??''))){self::view('操作できません','<p>CSRFを確認できません。</p>',403);return;}
        $info=$_FILES['avatar']??null;
        if(!is_array($info)||!is_string($info['tmp_name']??null)||!is_uploaded_file($info['tmp_name'])){
            self::view('ファイルを確認','<p>アップロード済みの画像ファイルを選んでください。</p>',422);return;
        }
        $tmp=$info['tmp_name'];
        try {
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext=AvatarImagePolicy::validateUpload($info,is_string($mime)?$mime:'',@getimagesize($tmp),@filesize($tmp));
            $selected=(string)($_POST['avatar_format']??'image/jpeg');
            if($selected!==($ext==='png'?'image/png':'image/jpeg'))throw new \InvalidArgumentException('画像の選択形式と実際の形式が一致しません');
        }catch(\InvalidArgumentException $e){self::view('画像形式を確認','<p>512×512pxのJPGまたはPNG画像を確認してください。</p>',422);return;}
        $dimension=[AvatarImagePolicy::EDGE,AvatarImagePolicy::EDGE];
        // Never store the original user-controlled image bytes. Re-encode using GD to remove
        // metadata/polyglot payloads. If GD is unavailable, fail closed, not raw-file upload.
        if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg')||!function_exists('imagepng')){
            self::view('画像処理機能が利用できません','<p>サーバーのGD/JPEG/PNG画像変換機能が必要です。管理者へ連絡してください。</p>',503);return;
        }
        $bytes=@file_get_contents($tmp);
        $decoded=is_string($bytes)?@imagecreatefromstring($bytes):false;
        unset($bytes);
        if($decoded===false){self::view('画像の検証に失敗','<p>画像ファイルが破損しているか、変換できません。</p>',422);return;}
        $canvas=imagecreatetruecolor((int)$dimension[0],(int)$dimension[1]);
        if($canvas===false){imagedestroy($decoded);self::view('変換に失敗','<p>画像を変換できません。</p>',503);return;}
        // Transparent PNG remains PNG; JPEG is flattened to the preview background.
        if($ext==='png'){
            imagealphablending($canvas,false);imagesavealpha($canvas,true);
            $transparent=imagecolorallocatealpha($canvas,0,0,0,127);
            imagefill($canvas,0,0,$transparent);
        }else{
            imagealphablending($canvas,true);
            $bg=imagecolorallocate($canvas,227,216,213);
            imagefilledrectangle($canvas,0,0,imagesx($canvas)-1,imagesy($canvas)-1,$bg);
        }
        $ok=imagecopyresampled($canvas,$decoded,0,0,0,0,(int)$dimension[0],(int)$dimension[1],imagesx($decoded),imagesy($decoded));
        imagedestroy($decoded);
        if(!$ok){imagedestroy($canvas);self::view('変換に失敗','<p>画像を変換できません。</p>',503);return;}
        // Encode into a private 0600 staging file and promote only after validation.
        // The old avatar is never removed before database commit succeeds.
        try {
            $new=AvatarFileStore::save($dir,$ext,static function(string $stage)use($canvas,$ext):bool {
                return ($ext==='png'?@imagepng($canvas,$stage,6):@imagejpeg($canvas,$stage,85))===true;
            });
        }catch(\Throwable $e){
            imagedestroy($canvas);
            self::view('変換に失敗','<p>画像の変換または保存ができません。PNGの容量が大きい場合はJPEGを選んでください。</p>',422);return;
        }
        imagedestroy($canvas);
        $commitAttempted=false;
        try {
            if(!$db->beginTransaction())throw new \RuntimeException('Cannot begin avatar update');
            $db->prepare('INSERT INTO user_profiles(user_id,avatar_file) VALUES(?,?) ON DUPLICATE KEY UPDATE avatar_file=VALUES(avatar_file)')->execute([$id,$new]);
            $commitAttempted=true;
            if(!$db->commit())throw new \RuntimeException('Cannot commit avatar update');
        }catch(\Throwable $e){
            if($db->inTransaction())try{$db->rollBack();}catch(\Throwable $rollbackError){error_log('KMM avatar rollback failed');}
            // A failed COMMIT can have an ambiguous outcome; keep the new file
            // in that case rather than risk breaking a committed DB reference.
            if(!$commitAttempted)AvatarFileStore::discard($dir,$new);
            error_log('KMM avatar database save failed: '.get_class($e));
            self::view('保存できません','<p>プロフィール画像を保存できませんでした。以前の画像はそのままご利用いただけます。</p>',503);return;
        }
        if(is_string($name)&&$name!==$new)AvatarFileStore::discard($dir,$name);
        header('Location: /account/profile',true,303);
    }
    private static function security(array $user,string $method):void {
        $db=Database::connected();$uid=(int)$user['id'];$notice='';
        if($method==='POST') {
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::view('操作を確認','<p>CSRF認証に失敗しました。</p>',403);return;}
            if(!Throttle::allow('password-change:'.$uid,6,900)){self::view('操作制限','<p>しばらくしてからお試しください。</p>',429);return;}
            if((string)($_POST['action']??'')!=='change'){self::view('入力エラー','<p>変更操作を確認できません。</p>',422);return;}
            try {
                $ok=AccountPasswordService::change($db,$uid,(string)($_POST['current_password']??''),
                    (string)($_POST['password']??''),(string)($_POST['confirm']??''));
                if($ok){unset($_SESSION['pending_password_change_token']);Identity::signOut();header('Location: /account/login?password_changed=1',true,303);return;}
                $notice='現在のパスワード、確認欄、または新しいパスワードの条件を確認してください。';
            }catch(\Throwable $e){error_log('KMM local password change failed: '.get_class($e));self::view('変更できません','<p>変更を保存できませんでした。</p>',503);return;}
        }elseif($method!=='GET'){self::view('許可されない操作','<p>この操作はできません。</p>',405);return;}
        $html=self::head('セキュリティ','現在のパスワードを確認して変更します。メール認証とSMTPは不要です。変更後はすべてのログイン状態と未使用の認証リンクを無効にします。');
        if($notice!=='')$html.='<p role="alert" class="note">'.Security::h($notice).'</p>';
        $html.='<p><a class="btn ghost" href="/account/center">← 前のページに戻る</a></p>';
        $html.='<form class="setup-form" method="post" action="/account/security">'.Session::csrfField().'<input type="hidden" name="action" value="change">'
            .'<label>現在のパスワード<input type="password" name="current_password" required autocomplete="current-password"></label><p class="password-recovery-link"><a class="text-link" href="/account/forgot-password">パスワードは忘れましたか？</a></p>'
            .'<label>新しいパスワード（8文字以上・2種類以上）<input type="password" name="password" minlength="8" maxlength="256" required autocomplete="new-password"></label>'
            .'<label>新しいパスワード（確認）<input type="password" name="confirm" minlength="8" maxlength="256" required autocomplete="new-password"></label>'
            .'<button class="btn primary">パスワードを変更する</button></form>';
        self::view('セキュリティ',$html);
    }
    private static function verifyEmail(string $method):void {
        $db=Database::connected();$token=(string)($_POST['token']??$_GET['token']??'');
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::view('操作を確認','<p>CSRF認証に失敗しました。</p>',403);return;}
            $ok=TokenService::consumeEmailVerification($db,$token);
            self::view($ok?'確認完了':'無効なリンク','<p>'.($ok?'新しいメールアドレスを確認しました。':'リンクが期限切れ、またはメールアドレスが変更されています。').'</p><a class="btn ghost" href="/account/notifications">メール設定へ戻る</a>',$ok?200:422);return;
        }
        if($method!=='GET'){self::view('許可されない操作','<p>この操作はできません。</p>',405);return;}
        $valid=TokenService::inspect($db,$token,'email_verify')!==null;
        $html=self::head('新しいメールアドレスの検証',$valid?'受け取ったメールのリンクから確認を完了できます。':'リンクが期限切れ、または無効です。');
        if($valid)$html.='<form method="post" action="/account/verify-email">'.Session::csrfField().'<input type="hidden" name="token" value="'.Security::h($token).'"><button class="btn primary">このメールアドレスを確認する</button></form>';
        self::view('メールの確認',$html,$valid?200:422);
    }

}
