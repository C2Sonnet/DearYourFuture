<?php
declare(strict_types=1);
namespace KMM\Core;
final class Kernel {
    public static function handle(): void {
        Security::headers();
        header('Content-Type: text/html; charset=utf-8');
        $path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
        if(!is_string($path)||strlen($path)>400||!str_starts_with($path,'/')){self::error(400,'リクエストを確認してください');return;}
        try {Session::start();}catch(\Throwable){self::error(503,'HTTPSと安全なセッションが必要です');return;}
        $state=SiteState::state();
        if($state!=='ready'){
            if(str_starts_with($path,'/initial')){Installer::handle($path,$method);return;}
            if($state==='new' && Security::previewAllowed() && $method==='GET'){
                self::preview($path);return;
            }
            if($state==='new'){header('Location: /initial',true,302);return;}
            self::error(503,'インストール状態に不整合があります。復旧が必要です');return;
        }
        if(str_starts_with($path,'/initial')){header('Location: /',true,302);return;}
        try {
            $db=Database::connected();
            $countdown=PreviewCountdown::state($db);
            if($path==='/countdown'){
                $member=Identity::current();
                $preview=$member!==null&&in_array((string)$member['role'],['FOUNDER','DEVELOPER'],true)&&($_GET['preview']??'')==='1';
                if(($countdown['enabled']&&$member===null)||$preview){PreviewCountdown::handle($method,$countdown['end']);return;}
                header('Location: /',true,302);return;
            }
            if($countdown['enabled']&&Identity::current()===null&&!PreviewCountdown::isExempt($path)){
                if(($_SERVER['HTTP_HX_REQUEST']??'')==='true'){http_response_code(503);header('HX-Redirect: /countdown');return;}
                if(str_starts_with($path,'/api/')){http_response_code(503);header('Content-Type: application/json');echo '{"error":"countdown_active"}';return;}
                PreviewCountdown::handle($method,$countdown['end']);return;
            }
            // A separate, session-bound SiteGate protects all non-admin routes when enabled.
            $gateOn=SiteGate::enabled($db);
            if(SiteGate::isRoute($path)) {SiteGate::handle($db,$path,$method);return;}
            if($gateOn && !SiteGate::isExempt($path) && !PreviewCountdown::isExempt($path) && !SiteGate::passed($db)) {
                if(str_starts_with($path,'/api/')){http_response_code(423);header('Content-Type: application/json');echo '{"error":"site_gate_required"}';return;}
                if(($_SERVER['HTTP_HX_REQUEST']??'')==='true'){http_response_code(423);header('HX-Redirect: /site-access');return;}
                header('Location: /site-access?next='.rawurlencode(Session::safeReturn((string)($_SERVER['REQUEST_URI']??'/'))),true,302);return;
            }
            $guest=Database::settings($db,'access.guest_browsing_enabled');
            $reset=Database::settings($db,'account.password_reset_enabled');
            if(!is_bool($guest)||!is_bool($reset)){self::error(503,'サイトの公開設定を確認できません');return;}
            if(AccountController::isRoute($path)){
                if(($path==='/account/reset-password'||$path==='/account/forgot-password') && !$reset){self::error(404,'この機能は停止中です');return;}
                AccountController::handle($path,$method);return;
            }
            if(ApplicationController::isRoute($path) && $path==='/account/center'){ApplicationController::handle($path,$method);return;}
            if(ProfileController::isRoute($path)){ProfileController::handle($path,$method);return;}
            $user=Identity::current();
            $decision=AccessPolicy::decision($path,$method,$user!==null,$guest,$reset);
            if($decision==='login'){
                if(str_starts_with($path,'/api/')){http_response_code(401);header('Content-Type: application/json');echo '{"error":"authentication_required"}';return;}
                if(isset($_SERVER['HTTP_HX_REQUEST'])&&$_SERVER['HTTP_HX_REQUEST']==='true'){
                    http_response_code(401);header('HX-Redirect: /account/login');return;
                }
                $next=Session::safeReturn((string)($_SERVER['REQUEST_URI']??'/'));
                if($path==='/admin'||str_starts_with($path,'/admin/')){header('Location: /admin/login',true,302);return;}
                header('Location: /account/login?next='.rawurlencode($next),true,302);return;
            }
            if(str_starts_with($path,'/admin')){if($user===null){self::error(401,'ログインが必要です');return;}AdminController::handle($path,$method,$user);return;}
            if(MessagesController::isRoute($path)){MessagesController::handle($path,$method);return;}
            if(ContributionsController::isRoute($path)){ContributionsController::handle($path,$method);return;}
            if(ActivityController::isRoute($path)){ActivityController::handle($path,$method);return;}
            if(WorkspaceController::isRoute($path)){WorkspaceController::handle($path,$method);return;}
            if(EligibilityController::isRoute($path)){EligibilityController::handle($path,$method);return;}
            if(ReminderController::isRoute($path)){ReminderController::handle($method);return;}
            if(ApplicationController::isRoute($path)){ApplicationController::handle($path,$method);return;}
            if($path==='/map'){ResearchMapController::handle($method);return;}
            if(MapController::isRoute($path)){MapController::handle($path,$method);return;}
            if(LibraryController::isRoute($path)){LibraryController::handle($path,$method);return;}
            if(RecruitmentTimeline::isRoute($path)){RecruitmentTimeline::handle($method);return;}
            if(ComparisonCarts::isRoute($path)){ComparisonCarts::handle($path,$method);return;}
            if(SiteResourcesController::isRoute($path)){SiteResourcesController::handle($method);return;}
            if(GuideController::isRoute($path)){GuideController::handle($path,$method);return;}
            if(BusinessController::isRoute($path)){BusinessController::handle($path,$method);return;}
            if($path==='/'){HomeController::handle($method);return;}
            if($method!=='GET'){header('Allow: GET');self::error(405,'許可されない操作です');return;}
            if(isset(Pages::SECTIONS[$path])){self::section($path,Pages::SECTIONS[$path]);return;}
            self::error(404,'このページは見つかりません');
        }catch(\Throwable $e){
            error_log('KMM request failure: '.get_class($e));
            self::error(503,'現在、安全にこのページを表示できません');
        }
    }
    private static function preview(string $path): void {
        header('X-KMM-Preview: local-only');
        if($path==='/account'||str_starts_with($path,'/account/')||$path==='/admin'||str_starts_with($path,'/admin/')){
            self::error(501,'ローカルプレビューでは認証・管理機能は利用できません');return;
        }
        if($path==='/'){self::home();return;}
        if($path==='/guide'){self::layout('はじめての方へ',GuideController::render(null));return;}
        if($path==='/site-resources'){SiteResourcesController::handle('GET');return;}
        if($path==='/companies/compare'){self::layout('企業比較',CompanyCompareController::render([],[]));return;}
        if(isset(Pages::SECTIONS[$path])){self::section($path,Pages::SECTIONS[$path]);return;}
        if(str_starts_with($path,'/admin')||str_starts_with($path,'/account')){self::error(501,'ローカルプレビューでは認証・管理機能は利用できません');return;}
        self::error(404,'このページは見つかりません');
    }
    private static function home(): void {
        ob_start();?>
        <section class="hero"><span class="eyebrow">TOWARD YOUR FUTURE · v<?=Security::h(SiteState::displayVersion())?></span><img class="hero-cat" src="/assets/cat.svg" alt="見守る猫のイラスト"><h1>キミの未来へ、<br>小さな一歩から。</h1><p>気になる会社を見つけて、調べて、比べて。あなたのペースで就職活動を進めるための場所です。</p><a class="btn primary" href="/guide">はじめての方はこちら <span aria-hidden="true">↗</span></a><a class="btn ghost" href="/companies">企業を探す</a></section>
        <section class="section"><div class="section-head"><h2>まずは、ここから</h2><p>どこから始めるか迷ったら、この3ステップを。</p></div><div class="cards"><a href="/companies" class="panel"><span class="step">STEP 01</span><span class="emoji" aria-hidden="true">🔎</span><h3>企業を探す</h3><p>勤務地や仕事の内容を確認しましょう。</p></a><a href="/companies/compare" class="panel"><span class="step">STEP 02</span><span class="emoji" aria-hidden="true">♡</span><h3>気になる会社を比べる</h3><p>自分に合うポイントを探しましょう。</p></a><a href="/applications/timeline" class="panel"><span class="step">STEP 03</span><span class="emoji" aria-hidden="true">✿</span><h3>予定を整理する</h3><p>次の選考まで、できることから一つずつ。</p></a></div></section>
        <section class="banner"><h2>選考の結果は、あなたの価値ではありません。</h2><p>うまく進まない日があっても大丈夫。見つけたこと、準備したことを、次の一歩につなげていきましょう。</p><a class="text-link" href="/guide">ガイドを見る →</a></section>
        <?php self::layout('ホーム',ob_get_clean());
    }
    private static function section(string $path, array $data): void {
        ob_start(); ?>
        <section class="hero compact"><span class="eyebrow">GUIDE · PREVIEW</span><h1><?=Security::h($data['title'])?></h1><p><?=Security::h($data['intro'])?></p></section>
        <?php if ($path === '/companies'): ?>
          <div class="section-head"><h2>企業の確認例</h2><p>以下は過去の調査資料に登場する候補です。募集継続・所在地・勤務地は未再確認です。</p></div>
          <div class="cards">
          <?php foreach (self::examples() as $c): ?><article class="panel"><span class="step">参考候補 · 要再確認</span><h3><?=Security::h($c['name'])?></h3><p><?=Security::h($c['memo'])?></p><a class="text-link" href="/companies/compare">企業比較へ →</a></article><?php endforeach; ?>
          </div>
        <?php elseif ($path === '/applications' || $path === '/account/center'): ?>
        <div class="section-head"><h2>選考ステータス</h2><p>本人専用のデータ保存・変更は認証機能完成後に利用できます。</p></div>
        <div class="status-cards"><div class="status consideration">♡ 検討中</div><div class="status entry">✉ エントリー済</div><div class="status process">✦ 選考中</div><div class="status ended"><details class="status-explanation"><summary>✿ 選考終了 <span aria-hidden="true">ⓘ</span></summary><p>この応募の選考が終了した状態です。ほかの企業への応募も引き続き管理できます。</p></details></div><div class="status passed"><img src="/assets/ribbon.svg" alt="" aria-hidden="true">合格・おめでとうございます！</div></div>
        <?php elseif ($path === '/guide'): ?>
        <ol class="guide-list"><li><h2>気になる職種を決める</h2><p>商品開発・品質管理・製菓・製パンから、興味のある仕事を考えます。</p></li><li><h2>勤務地と職種を確かめる</h2><p>本社と実際の勤務先は違うことがあります。勤務地未確認の企業を東京勤務確定と扱わないでください。</p></li><li><h2>検討リストに保存する</h2><p>比べたい会社を選んでおきます。（個人アカウント機能は実装予定）</p></li><li><h2>応募と選考の予定を整理する</h2><p>締切や面接の予定、結果を自分の時間軸に残します。</p></li></ol>
        <?php elseif ($path === '/map'): ?>
        <div class="map-skeleton" role="note"><span aria-hidden="true">⌖</span><h2>勤務地確認済みのピン付き地図を準備中</h2><p>実在の企業でも確認できていない拠点には、推測でピンを配置しません。</p></div>
        <?php elseif ($path === '/applications/timeline'||$path==='/companies/example/timeline'): ?>
        <ol class="timeline"><li><b>検討開始</b><p>日付を記録</p></li><li><b>エントリー</b><p>応募日・応募職種</p></li><li><b>選考予定</b><p>説明会・面接・提出期限</p></li><li><b>結果を記録</b><p>合格／選考終了を自分だけの記録に</p></li></ol>
        <?php endif; ?>
        <section class="section"><div class="section-head"><h2>関連ページ</h2></div><div class="link-grid">
        <?php foreach (($data['links']??[]) as [$href,$text]): ?><a class="linked" href="<?=Security::h($href)?>"><?=Security::h($text)?> <span aria-hidden="true">→</span></a><?php endforeach;?></div></section>
        <?php self::layout($data['title'],ob_get_clean());
    }
    private static function examples(): array { return [
        ['name'=>'ピエール・エルメ・パリ','memo'=>'製菓専門職。2027卒と既卒向けの条件を分けて確認。'],
        ['name'=>'うかい／アトリエうかい','memo'=>'工房とレストランなど配属先の区別が必要。'],
        ['name'=>'トップス','memo'=>'工場と勤務地の確定情報を確認。'],
        ['name'=>'MareFox','memo'=>'会社住所と求人の職場住所が異なる可能性を確認。'],
        ['name'=>'エーデルワイス','memo'=>'製菓・製パンの職種、配属工程の違いを確認。'],
        ['name'=>'Raise','memo'=>'東京都と神奈川県の配属候補を分けて確認。'],
    ];}
    private static function error(int $code, string $message): void {
        http_response_code($code);
        self::layout((string)$code,'<section class="hero compact"><span class="eyebrow">'.Security::h((string)$code).'</span><h1>'.Security::h($message).'</h1><p>安全のため、現在はこの機能を利用できません。</p></section>',false);
    }
    private static function isNavCurrent(string $url,string $path):bool {
        return $path===$url||($url!=='/'&&str_starts_with($path,$url.'/'));
    }
    private static function navAnchor(string $url,string $label,string $path,string $icon=''):string {
        $current=self::isNavCurrent($url,$path);
        return '<a class="nav-item'.($current?' active':'').'"'.($current?' aria-current="page"':'').' href="'.Security::h($url).'">'.($icon!==''?Security::h($icon).' ':'').Security::h($label).'</a>';
    }
    public static function layout(string $title,string $body,bool $nav=true): void {
        // Administrative subpages must never suppress the shared navigation.
        $currentRoute=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
        if(is_string($currentRoute)&&str_starts_with($currentRoute,'/admin/')&&$currentRoute!=='/admin/login')$nav=true;
        $site='キミの未来へ';
        $navHtml='';
        if($nav){
            $requestPath=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
            if(!is_string($requestPath))$requestPath='/';
            foreach(Pages::NAV_GROUPS as $group){
                if(isset($group['url'])){$navHtml.=self::navAnchor($group['url'],$group['label'],$requestPath,$group['icon']??'');continue;}
                $items=$group['items'];$active=false;$children='';
                foreach($items as $item){
                    if(($item['admin_only']??false)===true){$navUser=SiteState::state()==='ready'?Identity::current():null;if($navUser===null||!in_array((string)($navUser['role']??''),['ADMIN','FOUNDER','DEVELOPER'],true))continue;}
                    if(self::isNavCurrent($item['url'],$requestPath))$active=true;
                    $children.=self::navAnchor($item['url'],$item['label'],$requestPath);
                }
                $navHtml.='<details class="nav-group"'.($active?' data-group-active="true"':'').'><summary>'.Security::h($group['label']).'</summary><div class="nav-group-items">'.$children.'</div></details>';
            }
        }
        $isResourcePage=is_string($currentRoute) && $currentRoute==='/site-resources';
        $name=Security::h($site);$ttl=Security::h($title);$rev=Security::h(SiteState::displayVersion());
        $signedIn=null;$unread=0;
        if(SiteState::state()==='ready'){
            try{$signedIn=Identity::current();if($signedIn&&$nav){$db=Database::connected();$now=time();if($now-(int)($_SESSION['kmm_update_check']??0)>120){ViewHistory::poll($db,(int)$signedIn['id']);$_SESSION['kmm_update_check']=$now;}$unread=MessageRepository::unread($db,(int)$signedIn['id']);}}
            catch(\Throwable $e){error_log('KMM header message data unavailable: '.get_class($e));}
        }
        $adminAlert='';
        if($signedIn && in_array((string)$signedIn['role'],['ADMIN','FOUNDER','DEVELOPER'],true)){
            try{$db=Database::connected();$oldCount=SeedRepository::pending($db);$entityCount=SeedEntityRepository::pending($db);$conflictCount=$oldCount+$entityCount;if($conflictCount>0){$adminAlert='<div class="uk-notice uk-notice-danger seed-conflict-alert" role="alert"><strong>原典更新の未解消競合：'.(int)$conflictCount.'件</strong> <a href="/admin/data/conflicts">企業基本情報</a> · <a href="/admin/data/entity-conflicts">ブランド・求人・勤務地（25件ずつ）</a></div>';}}
            catch(\Throwable $e){error_log('KMM conflict notice unavailable: '.get_class($e));}
        }
        $corner='';$mobileCorner='';$kimiModal='';
        if($signedIn){
            $display=Security::h((string)$signedIn['display_name']);
            $avatarFile=null;
            try{$stmt=Database::connected()->prepare('SELECT avatar_file FROM user_profiles WHERE user_id=?');$stmt->execute([(int)$signedIn['id']]);$avatarFile=$stmt->fetchColumn();}catch(\Throwable){}
            $avatar=is_string($avatarFile)&&preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/D',$avatarFile)?'<img class="avatar-picture" src="/account/avatar" alt="" loading="lazy">':'<span class="avatar-symbol" aria-hidden="true">✿</span>';
            $badge=$unread>0?'<span class="message-badge" aria-label="未読'.(int)$unread.'件">'.MessageData::badge($unread).'</span>':'';
            $icon='<span class="envelope" aria-hidden="true">✉</span>'.$badge;
            $adminShortcut=in_array((string)$signedIn['role'],['ADMIN','FOUNDER','DEVELOPER'],true)?'<a href="/admin">管理者パネル</a>':'';
            $kimiButton='';
            if(KimiIntro::allowed($signedIn)){
                $kimiButton='<button type="button" class="kimi-menu-action" data-kimi-open aria-haspopup="dialog" aria-controls="kimi-intro-modal">キミへ</button>';
                $kimiAuto=false;
                try{$kimiAuto=!empty($_SESSION['kmm_kimi_login_pending_v2'])&&!KimiIntro::acknowledged(Database::connected(),(int)$signedIn['id']);unset($_SESSION['kmm_kimi_login_pending_v2']);}
                catch(\Throwable $e){error_log('KMM first-visit marker unavailable: '.get_class($e));}
                $kimiModal=KimiIntro::modal($kimiAuto);
            }

            $corner='<div class="user-corner"><a class="compare-nav" data-compare-link="jobs" href="/compare/jobs">求人比較 <span data-compare-count="jobs">0</span></a><a class="compare-nav" data-compare-link="companies" href="/compare/companies">企業比較 <span data-compare-count="companies">0</span></a>'.$avatar.'<details class="user-menu"><summary><span class="user-display">'.$display.'</span><span class="sr-only">ユーザーメニュー</span></summary><div class="user-menu-panel">'.($kimiButton!==''?$kimiButton.'<hr class="menu-divider">':'').'<a href="/account/center">ユーザーセンター</a><a href="/account/profile">プロフィール</a><a href="/account/security">セキュリティ</a><a href="/applications">就活管理</a><a href="/my/favorites">お気に入り</a><hr class="menu-divider"><a href="/messages">メッセージ</a><a href="/messages/settings">メッセージ設定</a><a href="/account/notifications">メール設定</a><hr class="menu-divider">'.$adminShortcut.'<form method="post" action="/account/logout">'.Session::csrfField().'<button class="menu-button">ログアウト</button></form></div></details><a class="message-shortcut" href="/messages" aria-label="メッセージ受信箱・未読'.$unread.'件">'.$icon.'</a></div>';
            $mobileCorner='<div class="mobile-user"><a class="btn ghost" href="/compare/jobs" data-compare-link="jobs">求人比較</a><a class="btn ghost" href="/compare/companies" data-compare-link="companies">企業比較</a><div>'.$avatar.' '.$display.' <a class="message-shortcut" href="/messages" aria-label="メッセージ・未読'.$unread.'件">'.$icon.'</a></div>'.($kimiButton!==''?$kimiButton.'<hr class="menu-divider">':'').'<a href="/account/center">ユーザーセンター</a><a href="/account/profile">プロフィール</a><a href="/account/security">セキュリティ</a><a href="/applications">就活管理</a><a href="/my/favorites">お気に入り</a><hr class="menu-divider"><a href="/messages">メッセージ</a><a href="/messages/settings">メッセージ設定</a><a href="/account/notifications">メール設定</a><hr class="menu-divider">'.$adminShortcut.'<form method="post" action="/account/logout">'.Session::csrfField().'<button class="menu-button">ログアウト</button></form></div>';
        }else {$corner='<a class="text-link" href="/account/login">ログイン</a>';$mobileCorner='<a href="/account/login">ログイン</a>';}
        $adminSidebar='';$requestAdminPath=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);if(is_string($requestAdminPath)&&($requestAdminPath==='/admin'||str_starts_with($requestAdminPath,'/admin/'))&&$signedIn!==null){$adminSidebar=AdminMenuIndex::render($requestAdminPath,$signedIn);}
        $headerNav=$nav?'<nav aria-label="メインナビゲーション" class="site-nav desktop-navigation">'.$navHtml.'</nav>':'';
        $mobileNav=$nav?'<details class="mobile-menu"><summary>☰ ナビ</summary><nav aria-label="モバイルナビ">'.$navHtml.'</nav></details>':'';
        $mobileAccount=$signedIn!==null?'<details class="mobile-user-menu"><summary>♙ ユーザー</summary><div class="mobile-user-panel">'.$mobileCorner.'</div></details>':'';
        $loginNotices='';
        if($signedIn!==null){
            try{$loginNotices=LoginNotices::render(Database::connected(),$signedIn);}
            catch(\Throwable $e){error_log('KMM login notice failure: '.get_class($e));}
        }
        echo '<!doctype html><html lang="ja" data-theme="light" data-authenticated="'.($signedIn!==null?'1':'0').'" data-user-id="'.($signedIn!==null?(int)$signedIn['id']:0).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="description" content="食品・製菓・製パンの専門職探しを支援するサイト"><title>'.$ttl.' | '.$name.'</title>'.($isResourcePage?'<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.25.26/dist/css/uikit.min.css"><script src="https://cdn.jsdelivr.net/npm/uikit@3.25.26/dist/js/uikit.min.js" defer></script><script src="/assets/background-lightbox.js" defer></script>':'').'<link rel="stylesheet" href="/assets/site.css"><script src="/assets/site.js" defer></script><script src="/assets/nav-accordion.js" defer></script><script src="/assets/header-offset.js" defer></script>'.($kimiModal!==''?'<script src="/assets/kimi-intro.js" defer></script>':'').(str_contains($body,'data-countdown-ends=')?'<script src="/assets/countdown.js" defer></script>':'').(str_contains($body,'data-history-target=')?'<script src="/assets/recent-history.js" defer></script>':'').($signedIn!==null?'<script src="/assets/compare-cart.js" defer></script><script src="/assets/favorites.js" defer></script>':'').'</head><body><a class="skip" href="#main">メインコンテンツへ</a><header class="site-header"><div class="header-inside"><a class="brand" href="/">✿ '.$name.'</a>'.$headerNav.'<div class="actions">'.$corner.'</div>'.$mobileNav.$mobileAccount.'</div>'.$adminAlert.'</header>'.$kimiModal.$adminSidebar.$loginNotices.'<main id="main" tabindex="-1" class="container">'.$body.'</main>'.($signedIn!==null?'<aside class="compare-float" data-compare-floating hidden aria-label="比較カート"><button type="button" class="compare-float-handle" data-compare-toggle aria-expanded="true" aria-label="比較カートを収納する" title="比較カートを収納／展開">›</button><strong>比較カート</strong><p>求人</p><ul data-compare-list="jobs"></ul><a class="btn ghost" data-compare-float-link="jobs" href="/compare/jobs">求人の比較へ</a><button type="button" class="btn ghost" data-compare-clear="jobs">求人を一括で外す</button><p>企業</p><ul data-compare-list="companies"></ul><a class="btn ghost" data-compare-float-link="companies" href="/compare/companies">企業の比較へ</a><button type="button" class="btn ghost" data-compare-clear="companies">企業を一括で外す</button></aside>':'').'<div class="floating-tools" aria-label="画面操作"><button class="to-top" type="button" data-back-to-top aria-label="ページ上部に戻る" title="ページ上部に戻る">↑</button>'.($signedIn!==null?'<button class="theme-toggle" type="button" data-theme-toggle aria-label="ダークモードへ切り替える" title="テーマ切替">☾</button>':'').'</div><footer class="site-footer">あなたの一歩を、やさしく応援します。 <span>© Toward Your Future · v'.$rev.'</span><span><a href="/privacy.html">プライバシー</a></span><span>作者：<a href="https://im.c2s.me" rel="noopener noreferrer" target="_blank">光と影のソネット（キセキ）(@C2Sonnet)</a></span></footer></body></html>';
    }

}
