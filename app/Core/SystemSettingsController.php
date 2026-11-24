<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class SystemSettingsController {
    public static function isRoute(string $path): bool {
        return in_array($path,['/admin','/admin/system/mail','/admin/system/mail/templates','/admin/system/maps','/admin/site/gate','/admin/site/countdown','/admin/site/message-blocking'],true);
    }
    public static function handle(string $path,string $method,array $user):void {
        if($path==='/admin'){self::index($method,$user);return;}
        $role=(string)$user['role'];
        if(in_array($path,['/admin/system/mail','/admin/system/mail/templates','/admin/system/maps'],true)&&!Rbac::canTechnicalSettings($role)) {self::message('権限がありません',403);return;}
        if($path==='/admin/site/message-blocking'&&$role!=='DEVELOPER'){self::message('権限がありません',403);return;}
        if(in_array($path,['/admin/site/gate','/admin/site/countdown'],true)&&!($role==='DEVELOPER')){self::message('権限がありません',403);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::message('CSRF認証が必要です',403);return;}
            if((int)($_SESSION['authenticated_at']??0)<time()-900){header('Location: /admin/login',true,303);return;}
            if(!Throttle::allow('technical:'.(int)$user['id'],15,900)){self::message('操作回数を超えました',429);return;}
        }
        if($path==='/admin/system/mail'){self::mail($method,$user);return;}
        if($path==='/admin/system/mail/templates'){self::templates($method,$user);return;}
        if($path==='/admin/system/maps'){self::mapSettings($method,$user);return;}
        if($path==='/admin/site/gate'){self::gate($method,$user);return;}
        if($path==='/admin/site/countdown'){self::countdown($method,$user);return;}
        if($path==='/admin/site/message-blocking'){self::blocking($method,$user);return;}
    }
    /** Static role-aware navigation, no private message bodies or user metadata included. */
    public static function dashboardLinks(string $role): array {
        if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true))return [];
        $links=[
            ['/admin/users','ユーザー管理','初期パスワード設定による追加、停止、権限変更'],
            ['/admin/data','企業・求人・勤務地','会社、拠点、求人、出典の管理'],
            ['/admin/library','企業資料','公開・非公開・ファイル版管理'],
            ['/admin/proposals','会員からの提案','修正提案の審査と承認'],
            ['/admin/data/conflicts','企業基本データの競合','未解決の原典変更を安全に確認'],
            ['/admin/data/entity-conflicts','ブランド・求人・勤務地の競合','25件ずつ原典と編集内容を比較'],
            ['/admin/data/history','変更履歴','CSV・JSONLで履歴を照会'],
            ['/admin/data/imports','安全な履歴取込','ドライラン・重複検査'],
            ['/admin/messages','メッセージ管理','配信メタデータと運用管理'],
            ['/admin/messages/reports','通報審査','本文は必要な理由と監査を伴う別操作'],
            ['/admin/messages/broadcasts','システム一斉通知','下書き・キュー・配信状況'],
            ['/admin/messages/weekly-report','週報設定','曜日・時刻・頻度・本文テンプレート'],
            ['/admin/site/access-policy','未ログイン閲覧','許可された公開ページの範囲'],
        ];
        if(($role==='DEVELOPER'))$links[]=['/admin/site/countdown','公開前カウントダウン','未ログイン閲覧制限・終了日時・ON/OFFを管理'];
        if(($role==='DEVELOPER'))$links[]=['/admin/site/gate','サイト共通パスワード','一般ユーザーのログインとは別の保護'];
        if($role==='DEVELOPER')$links[]=['/admin/site/message-blocking','ブロック機能の許可','一般会員へのブロック機能の開放を管理'];
        if(Rbac::canTechnicalSettings($role)){
            $links[]=['/admin/developer/kimi','「キミへ」の閲覧設定','対象指定と既読リセット'];
            $links[]=['/admin/developer/activity','ユーザーの就職活動情報','本人の活動データの管理'];
            $links[]=['/admin/system/mail','SMTP技術設定','開発者専用・暗号化保存、送信テスト'];
            $links[]=['/admin/system/maps','Googleマップ設定','APIキーとピン表示の設定'];
            $links[]=['/admin/system/mail/templates','発信メールテンプレート','4種類の初期テンプレートと本文を管理'];
            $links[]=['/admin/system/diagnostics','環境診断','PHP・拡張・Schemaの安全な状態表示'];
            $links[]=['/admin/system/updater','署名付き更新','事前検証と保守CLIの手順'];
            $links[]=['/admin/system/recovery','バックアップ・復旧','データ保護と検証手順'];
        }
        return $links;
    }
    private static function index(string $method,array $user):void {
        if($method!=='GET'){self::message('許可されない操作です',405);return;}
        $role=(string)$user['role'];
        $html='<section class="hero compact"><span class="eyebrow">ADMIN CONTROL CENTER</span><h1>管理パネル</h1><p>現在の役割：'.Security::h($role).'。機能ごとのサーバー権限も別途確認します。</p></section>';
        $html.='<div class="cards admin-dashboard">';
        foreach(self::dashboardLinks($role) as [$url,$title,$description]){
            $html.='<a class="panel" href="'.Security::h($url).'"><h2>'.Security::h($title).'</h2><p>'.Security::h($description).'</p></a>';
        }
        $html.='</div><p class="note">原典変更の未解消競合と通報は、適切な審査後に反映します。ログイン済みでも、役割外の設定・私信は参照できません。</p>';
        Kernel::layout('管理パネル',$html,false);
    }
    /** Developer-only toggle for ordinary members; safe OFF on missing legacy setting. */
    private static function blocking(string $method,array $user):void {
        $db=Database::connected();
        try{$enabled=Database::settings($db,'messages.blocking_for_members')===true;}catch(\RuntimeException){$enabled=false;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::message('CSRF認証が必要です',403);return;}
            $value=(string)($_POST['enabled']??'');
            if(!in_array($value,['on','off'],true)||($_POST['confirmed']??'')!=='yes'){self::message('入力を確認してください',422);return;}
            $next=$value==='on';
            $db->beginTransaction();
            try{
                $db->prepare("INSERT INTO system_settings(setting_key,value_json) VALUES('messages.blocking_for_members',?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()")
                  ->execute([json_encode($next,JSON_THROW_ON_ERROR)]);
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                  ->execute([(int)$user['id'],'message.blocking.policy.changed','setting','messages.blocking_for_members',json_encode(['before'=>$enabled,'after'=>$next],JSON_THROW_ON_ERROR)]);
                $db->commit();header('Location: /admin/site/message-blocking?updated=1',true,303);return;
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();self::message('保存できません',503);return;}
        }
        if($method!=='GET'){self::message('許可されない操作',405);return;}
        $h='<section class="hero compact"><h1>ブロック機能の開放</h1><p>初期は開発者のみ利用できます。開放しても開発者・自分自身・@MinaHelperはブロックできません。</p></section>';
        if(isset($_GET['updated']))$h.='<p class="note" role="status">設定を保存しました。</p>';
        $h.='<form class="setup-form" method="post">'.Session::csrfField().'<label><input type="radio" name="enabled" value="off"'.(!$enabled?' checked':'').'> OFF（開発者のみ）</label><label><input type="radio" name="enabled" value="on"'.($enabled?' checked':'').'> ON（一般会員も利用可能）</label><label><input type="checkbox" name="confirmed" value="yes" required>権限の変更を確認しました</label><button class="btn primary" type="submit">設定を保存する</button></form>';
        Kernel::layout('ブロック機能の許可',$h,false);
    }
    /** Map JS API key is public; use referrer restriction and optional production map ID. */
    private static function mapSettings(string $method,array $user):void {
        $db=Database::connected();$key=ResearchMapController::key($db);$mapId=ResearchMapController::mapId($db);
        if($method==='POST'){
            $next=trim((string)($_POST['browser_key']??''));
            $nextMapId=trim((string)($_POST['map_id']??''));
            if($next!==''&&!preg_match('/\AAIza[0-9A-Za-z_-]{25,90}\z/D',$next)){self::message('ブラウザ用キーの形式を確認してください',422);return;}
            if($nextMapId!==''&&!preg_match('/\A[a-fA-F0-9]{16}\z/D',$nextMapId)){self::message('Google Cloudで発行した16桁のマップIDを確認してください',422);return;}
            if($next!==''&&($_POST['restricted']??'')!=='yes'){self::message('サイトドメインへの参照元制限を確認してください',422);return;}
            $db->beginTransaction();
            try{
                $q=$db->prepare('INSERT INTO system_settings(setting_key,value_json) VALUES (?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
                $q->execute(['maps.google.browser_key',json_encode($next,JSON_THROW_ON_ERROR)]);
                $q->execute(['maps.google.map_id',json_encode($nextMapId,JSON_THROW_ON_ERROR)]);
                self::audit((int)$user['id'],'maps.browser_key.updated');
                $db->commit();
                header('Location: /admin/system/maps?saved=1',true,303);return;
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();self::message('地図設定を保存できません',503);return;}
        }
        if($method!=='GET'){self::message('許可されない操作',405);return;}
        $html='<section class="hero compact"><h1>Google マップ設定</h1><p>Maps JavaScript API・Geocoding APIを有効にしたブラウザ用キーを登録します。キーはブラウザへ渡されるため、HTTPリファラ制限を必ず設定してください。高度なマーカーにはGoogle Cloudで発行したマップIDが必要です。未設定なら通常のマーカーへ自動切替します。</p></section>';
        if(isset($_GET['saved']))$html.='<p class="note">地図キーとマップIDを保存しました。</p>';
        $html.='<form method="post" class="setup-form">'.Session::csrfField()
            .'<label>Google MapsブラウザAPIキー<input name="browser_key" type="text" autocomplete="off" maxlength="100" value="'.Security::h($key).'"></label>'
            .'<label>Google Cloud マップID（任意・16桁の16進数）<input name="map_id" type="text" autocomplete="off" maxlength="16" pattern="[A-Fa-f0-9]{16}" value="'.Security::h($mapId).'"></label>'
            .'<label class="preference-checkbox"><input name="restricted" type="checkbox" value="yes"'.($key!==''?' checked':'').'>本サイトのHTTPSドメインにHTTPリファラ制限を設定済みです</label>'
            .'<button class="btn primary">地図設定を保存</button></form><p><a class="btn ghost" href="/map">全企業の拠点マップを確認</a></p>';
        Kernel::layout('Googleマップ設定',$html,false);
    }
    private static function mail(string $method,array $user):void {
        $cfg=null;$notice='';
        try{$cfg=MailConfig::load();}catch(\Throwable){$notice='保存済みSMTP設定を復号できません。暗号化キーと設定ファイルを確認してください。';}
        if($method==='POST'){
            $action=(string)($_POST['action']??'save');
            if($action==='save'){
                $pass=(string)($_POST['password']??'');
                if($pass===''&&$cfg!==null)$pass=(string)$cfg['password'];
                $new=['host'=>$_POST['host']??'','port'=>$_POST['port']??0,'username'=>$_POST['username']??'',
                    'password'=>$pass,'from'=>$_POST['from']??'','sender_name'=>$_POST['sender_name']??'',
                    'tls_mode'=>$_POST['tls_mode']??'','ca_mode'=>$_POST['ca_mode']??'system'];
                try {MailConfig::save($new);$notice='SMTP設定を暗号化して保存しました。';$cfg=MailConfig::load();
                    self::audit((int)$user['id'],'system.smtp.updated');
                }catch(\Throwable $e){$notice='設定内容・証明書を確認してください。';}
            }elseif($action==='test'){
                $email=trim((string)($_POST['test_email']??''));
                if((string)($_POST['test_confirm']??'')!=='yes'||!Throttle::allow('smtp-test:'.(int)$user['id'],3,3600)){self::message('送信確認または回数制限を確認してください',429);return;}
                try {SmtpMailer::send($email,'【キミの未来へ】SMTP接続テスト','このメールはSMTP送信試験です。');$notice='SMTPサーバーがテストメールを受理しました（最終配達は未確認）。下の送信ログも確認してください。';
                    self::audit((int)$user['id'],'system.smtp.test');
                }catch(SmtpDeliveryException $e){$notice='テスト送信失敗：'.$e->publicSummary().'。下の送信ログを参照してください。';}
                catch(\Throwable $e){error_log('KMM smtp test failed: '.get_class($e));$notice='テスト送信に失敗しました。送信ログとPHPエラーログを確認してください。';}
            }else{$notice='操作が不明です。';}
        }elseif($method!=='GET'){self::message('許可されない操作',405);return;}
        $cfg=$cfg??['host'=>'','port'=>587,'username'=>'','from'=>'','sender_name'=>'キミの未来へ','tls_mode'=>'starttls','ca_mode'=>'system'];
        $h=fn($v)=>Security::h((string)$v);
        $html='<section class="hero compact"><span class="eyebrow">DEVELOPER ONLY</span><h1>SMTPメール設定</h1><p>証明書検証は常に有効です。SMTP認証情報は非公開領域に暗号化保存されます。</p></section>';
        if($notice)$html.='<p role="status" class="note">'.$h($notice).'</p>';
        $html.='<form class="setup-form" action="/admin/system/mail" method="post">'.Session::csrfField().'<input type="hidden" name="action" value="save">'.
            '<label>サーバーホスト<input name="host" required value="'.$h($cfg['host']).'"></label>'.
            '<label>ポート<input type="number" name="port" min="1" max="65535" required value="'.$h($cfg['port']).'"></label>'.
            '<label>ユーザー<input name="username" autocomplete="off" value="'.$h($cfg['username']).'"></label>'.
            '<label>パスワード（変更しない場合は空欄）<input type="password" name="password" autocomplete="new-password"></label>'.
            '<label>送信元アドレス<input type="email" name="from" required value="'.$h($cfg['from']).'"></label>'.
            '<label>表示名<input name="sender_name" required value="'.$h($cfg['sender_name']).'"></label>'.
            '<label>TLS形式<select name="tls_mode"><option value="starttls"'.($cfg['tls_mode']==='starttls'?' selected':'').'>STARTTLS（通常587）</option><option value="implicit"'.($cfg['tls_mode']==='implicit'?' selected':'').'>Implicit TLS（通常465）</option></select></label>'.
            '<label>証明書CA<select name="ca_mode"><option value="system"'.($cfg['ca_mode']==='system'?' selected':'').'>OSの証明書ストア</option><option value="custom"'.($cfg['ca_mode']==='custom'?' selected':'').'>非公開mail-ca.pem</option></select></label>'.
            '<button class="btn primary" type="submit">SMTP設定を保存</button></form>';
        if(MailConfig::configured())$html.='<form class="setup-form" action="/admin/system/mail" method="post">'.Session::csrfField().'<input type="hidden" name="action" value="test"><label>テスト送信先<input type="email" name="test_email" required></label><label class="preference-checkbox"><input type="checkbox" name="test_confirm" value="yes" required>指定アドレスへテストメール1通を送信することを確認しました</label><button class="btn ghost" type="submit">発信テスト</button></form>';
        $html.='<section class="panel"><h2>SMTP送信機能の使用可否（ローカル診断）</h2><p class="note">PHPの関数・拡張と設定の検査です。ネットワーク接続・認証・実配送の成功を保証しません。</p><table><thead><tr><th>確認項目</th><th>状態</th></tr></thead><tbody>';
        foreach(SmtpAudit::diagnostics() as $name=>$status)$html.='<tr><th scope="row">'.$h($name).'</th><td>'.$h($status).'</td></tr>';
        $html.='</tbody></table></section>';
        $html.='<section class="panel"><h2>SMTP送信ログ（直近15件）</h2><p class="note">UTC日時・成功/失敗・処理段階・SMTP応答コードのみを非公開領域に記録します。メールアドレス、本文、パスワード、サーバーの応答本文は保存しません。SMTPの250受理は受信者への配達完了を意味しません。</p>';
        $recent=SmtpAudit::recent(15);
        if($recent===[])$html.='<p>記録はありません。発信テスト後に再読み込みしてください。</p>';
        else {$html.='<table><thead><tr><th>日時（UTC）</th><th>結果</th><th>段階</th><th>SMTPコード</th><th>接続エラー番号</th></tr></thead><tbody>';
            foreach($recent as $row)$html.='<tr><td>'.$h($row['utc']??'').'</td><td>'.$h($row['result']??'').'</td><td>'.$h($row['phase']??'').'</td><td>'.$h($row['smtp_code']??'-').'</td><td>'.$h($row['socket_errno']??'-').'</td></tr>';
            $html.='</tbody></table>';}
        $html.='<p class="note">保存先：storage/private/smtp-delivery.jsonl（Web公開領域外、0600）。ログの収集・削除・保全期間は運営者が管理してください。</p></section>';
        $html.='<p><a class="btn ghost" href="/admin/system/mail/templates">発信テンプレート管理</a></p>';
        Kernel::layout('SMTP設定',$html,false);
    }
    /** Developer-only templates: known keys and placeholders, text-only email. */
    private static function templates(string $method,array $user):void {
        $db=Database::connected();$notice='';
        if($method==='POST'){
            $id=(string)($_POST['template_id']??'');
            try{
                if(!isset(MailNotifications::DEFAULTS[$id]))throw new \InvalidArgumentException('Invalid template');
                $subject=(string)($_POST['subject']??'');$body=(string)($_POST['body']??'');
                MailNotifications::saveTemplate($db,$id,$subject,$body,(int)$user['id']);
                header('Location: /admin/system/mail/templates?saved=1',true,303);return;
            }catch(\InvalidArgumentException $e){$notice='件名・本文・テンプレート変数を確認してください。';}
            catch(\Throwable $e){error_log('KMM mail template update failed: '.get_class($e));$notice='テンプレートを保存できませんでした。';}
        }elseif($method!=='GET'){self::message('許可されない操作',405);return;}
        $html='<section class="hero compact"><h1>発信メールテンプレート管理</h1><p>プレーンテキストのみ。利用できる変数：{{site_name}}、{{user_name}}、{{event_time}}、{{detail}}、{{url}}。保存だけではメール送信しません。</p></section>';
        if(isset($_GET['saved']))$html.='<p class="note" role="status">テンプレートを保存しました。</p>';
        if($notice!=='')$html.='<p class="note" role="alert">'.Security::h($notice).'</p>';
        foreach(MailNotifications::DEFAULTS as $id=>$default){
            try{$t=MailNotifications::template($db,$id);}catch(\Throwable){$t=$default;$html.='<p class="note">設定を読み取れませんでした。管理者の確認が必要です。</p>';}
            $html.='<form class="setup-form mail-template-form" action="/admin/system/mail/templates" method="post">'.Session::csrfField().'<input type="hidden" name="template_id" value="'.Security::h($id).'">'
                .'<h2>'.Security::h($t['name']).'</h2><label>件名<input name="subject" required maxlength="160" value="'.Security::h($t['subject']).'"></label>'
                .'<label>本文<textarea name="body" rows="8" required maxlength="4000">'.Security::h($t['body']).'</textarea></label>'
                .'<button class="btn primary" type="submit">このテンプレートを保存</button></form>';
        }
        $html.='<p><a class="btn ghost" href="/admin/system/mail">SMTPメール設定へ戻る</a></p>';
        Kernel::layout('発信メールテンプレート管理',$html,false);
    }
    private static function countdown(string $method,array $user):void {
        $db=Database::connected();$notice='';
        if($method==='POST'){
            try{
                $choice=(string)($_POST['enabled']??'');
                if(!in_array($choice,['on','off'],true))throw new \InvalidArgumentException('Invalid switch value');
                $enabled=$choice==='on';
                PreviewCountdown::configure($db,$enabled,(string)($_POST['ends_at_jst']??''));
                self::audit((int)$user['id'],'site.countdown.updated');
                header('Location: /admin/site/countdown?saved=1',true,303);return;
            }catch(\InvalidArgumentException $e){$notice='終了日時を確認してください。ONにする場合は未来の日本時間を指定してください。';}
            catch(\Throwable $e){error_log('KMM countdown configuration failed: '.get_class($e));$notice='保存できませんでした。DB設定を確認してください。';}
        }elseif($method!=='GET'){self::message('許可されない操作',405);return;}
        $s=PreviewCountdown::state($db);
        $ends=$s['end']->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d\TH:i');
        $html='<section class="hero compact"><h1>公開前カウントダウン</h1><p>ログインしていない訪問者には公開準備中ページを表示します。期限到達後は自動でOFFになります。パスワード再設定とメール検証は引き続き利用可能です。</p></section>';
        if(isset($_GET['saved']))$html.='<p class="note" role="status">カウントダウン設定を保存しました。</p>';
        if($notice!=='')$html.='<p class="note" role="alert">'.Security::h($notice).'</p>';
        $html.='<p class="note">現在の状態：'.($s['enabled']?'ON':'OFF').'／終了：'.Security::h($s['end']->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y年n月j日 H:i')).'（日本時間）</p>';
        $html.='<form class="setup-form" method="post" action="/admin/site/countdown">'.Session::csrfField()
            .'<fieldset><legend>公開前表示</legend><label><input type="radio" name="enabled" value="on"'.($s['enabled']?' checked':'').'> ON</label><label><input type="radio" name="enabled" value="off"'.(!$s['enabled']?' checked':'').'> OFF</label></fieldset>'
            .'<label>終了日時（日本時間）<input type="datetime-local" name="ends_at_jst" required value="'.Security::h($ends).'"></label>'
            .'<p class="note">初期設定：2026年11月23日16:00。期限を過ぎた状態では再びONにする前に終了日時を変更してください。</p>'
            .'<button class="btn primary" type="submit">設定を保存</button></form><p><a class="btn ghost" href="/countdown?preview=1">公開前ページを確認</a></p>';
        Kernel::layout('カウントダウン管理',$html,false);
    }
    private static function gate(string $method,array $user):void {
        $db=Database::connected();$enabled=SiteGate::enabled($db);$notice='';
        if($method==='POST'){
            $next=(string)($_POST['enabled']??'off');
            if(!in_array($next,['on','off'],true)){$notice='選択値を確認してください。';}
            else {
                try {SiteGate::configure($db,$next==='on',(string)($_POST['gate_password']??''));
                    self::audit((int)$user['id'],'site.gate.updated');$enabled=SiteGate::enabled($db);$notice='アクセス保護を更新しました。以前の通過状態は無効です。';
                }catch(\Throwable $e){$notice='パスワードまたは設定を確認してください。有効化には12文字以上のパスワードが必要です。';}
            }
        }elseif($method!=='GET'){self::message('許可されない操作',405);return;}
        $html='<section class="hero compact"><h1>サイト共通パスワード</h1><p>一般ユーザーのログインとは別の保護です。初期値はOFFです。</p></section>';
        if($notice)$html.='<p class="note" role="status">'.Security::h($notice).'</p>';
        $html.='<form method="post" action="/admin/site/gate" class="setup-form">'.Session::csrfField().
            '<label><input type="radio" name="enabled" value="off"'.(!$enabled?' checked':'').'> OFF</label>'.
            '<label><input type="radio" name="enabled" value="on"'.($enabled?' checked':'').'> ON</label>'.
            '<label>共通パスワード（変更がなければ空欄）<input type="password" name="gate_password" minlength="12" autocomplete="new-password"></label>'.
            '<button class="btn primary">保存する</button></form>';
        Kernel::layout('共通パスワード',$html,false);
    }
    private static function audit(int $id,string $event):void {
        $db=Database::connected();$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$id,$event,'settings','mail-or-gate','{}']);
    }
    private static function message(string $text,int $status):void {
        http_response_code($status);Kernel::layout('管理パネル','<section class="hero compact"><h1>'.Security::h($text).'</h1></section>',false);
    }
}
