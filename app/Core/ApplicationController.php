<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

final class ApplicationController {
    public static function isRoute(string $path):bool {
        return in_array($path,['/applications','/applications/timeline','/applications/calendar','/applications/track','/applications/event','/my','/account/center'],true)
            || preg_match('~\A/companies/[a-f0-9]{32}/timeline\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        $user=Identity::current();
        if(!$user){header('Location: /account/login?next='.rawurlencode(Session::safeReturn((string)($_SERVER['REQUEST_URI']??'/applications'))),true,302);return;}
        $db=Database::connected();$id=(int)$user['id'];
        if($method==='POST') {self::post($db,$id,$path);return;}
        if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');self::view('許可されない操作','<p>この操作は利用できません。</p>');return;}
        if($path==='/applications/track'||$path==='/applications/event'){self::view('ページがありません','<p>この操作はフォームから行ってください。</p>',404);return;}
        if($path==='/account/center'){self::center($db,$id,$user);return;}
        if($path==='/applications/calendar'){self::timeline($db,$id,null,true);return;}
        if($path==='/applications/timeline'){self::timeline($db,$id);return;}
        if(preg_match('~\A/companies/([a-f0-9]{32})/timeline\z~D',$path,$match)){
            if(!BusinessRepository::read($db,'companies',$match[1],true) && ApplicationRepository::companyTracks($db,$id,$match[1])===[]){self::view('企業が見つかりません','<p>企業を参照できません。</p>',404);return;}
            self::timeline($db,$id,$match[1]);return;
        }
        self::listing($db,$id,$path);
    }
    private static function post(PDO $db,int $uid,string $path):void {
        if(!in_array($path,['/applications/track','/applications/event'],true)){self::view('許可されない操作','<p>この操作は利用できません。</p>',405);return;}
        if(!Session::validCsrf((string)($_POST['csrf']??''))){self::view('操作を確認できません','<p>再度ページを開いてお試しください。</p>',403);return;}
        if(!Throttle::allow('app-write:'.$uid,40,900)){self::view('操作制限','<p>少し時間をおいてください。</p>',429);return;}
        try {
            if($path==='/applications/track'){
                $companyId=BusinessData::id((string)($_POST['company_id']??''));
                $jobId=trim((string)($_POST['job_id']??''));$jobId=$jobId===''?null:BusinessData::id($jobId);
                $revision=($_POST['revision']??'')===''?null:filter_var($_POST['revision'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($revision===false)throw new \InvalidArgumentException('Invalid revision');
                $id=ApplicationRepository::saveStatus($db,$uid,$companyId,$jobId,ApplicationData::status($_POST['status']??null),$revision);
                $next='/applications?updated=1';
                if(($_POST['return_to']??'')==='timeline')$next='/companies/'.$companyId.'/timeline';
            }else{
                $action=(string)($_POST['action']??'create');$trackId=BusinessData::id((string)($_POST['track_id']??''));
                $track=ApplicationRepository::one($db,$uid,$trackId);
                if(!$track)throw new \RuntimeException('No permission to edit this application');
                $rev=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($action==='create')ApplicationRepository::addEvent($db,$uid,$trackId,$_POST);
                elseif($action==='reschedule'&&$rev!==false&&$rev!==null)ApplicationRepository::rescheduleEvent($db,$uid,BusinessData::id((string)($_POST['event_id']??'')),$_POST,$rev);
                elseif($action==='edit'&&$rev!==false&&$rev!==null)ApplicationRepository::editEvent($db,$uid,BusinessData::id((string)($_POST['event_id']??'')),$_POST,$rev);
                elseif($action==='delete'&&$rev!==false&&$rev!==null)ApplicationRepository::removeEvent($db,$uid,BusinessData::id((string)($_POST['event_id']??'')),$rev);
                else throw new \InvalidArgumentException('Invalid event operation');
                $next='/companies/'.$track['company_id'].'/timeline';
            }
            header('Location: '.$next,true,303);
        }catch(\InvalidArgumentException $e){self::view('入力内容をご確認ください','<p>'.Security::h($e->getMessage()).'</p>',422);}
         catch(\RuntimeException $e){self::view('変更を保存できません','<p>競合または権限の問題です。画面を更新してお試しください。</p>',409);}
    }
    public static function statusForm(string $companyId,?string $jobId=null,?array $track=null):string {
        $existing=$track!==null;$current=(string)($track['status']??'consideration');
        $html='<form method="post" action="/applications/track" class="app-status-form">'.Session::csrfField().
            '<input type="hidden" name="company_id" value="'.Security::h($companyId).'">'.
            '<input type="hidden" name="job_id" value="'.Security::h($jobId??'').'">';
        if($existing)$html.='<input type="hidden" name="revision" value="'.(int)$track['revision'].'">';
        $html.='<label>現在の選考状況<select name="status">';
        foreach(ApplicationData::LABELS as $value=>$label)$html.='<option value="'.Security::h($value).'"'.($current===$value?' selected':'').'>'.Security::h($label).'</option>';
        return $html.'</select></label><button type="submit" class="btn primary">'.($existing?'状態を更新':'選考状態を登録').'</button></form>';
    }
    private static function listing(PDO $db,int $uid,string $path):void {
        $filter=(string)($_GET['status']??'');if($filter!==''&&!in_array($filter,ApplicationData::STATUSES,true))$filter='';
        $page=ApplicationData::page($_GET['page']??'1');$tracks=ApplicationRepository::tracks($db,$uid,$filter!==''?$filter:null,25,($page-1)*25);
        $title=$path==='/my'?'マイリスト':'就活管理';
        $html=self::head($title,'各求人の進み方はあなた専用の記録です。企業の募集状況と混同しません。').self::nav();
        $html.='<div class="status-filter"><a href="'.$path.'"'.($filter===''?' class="active" aria-current="page"':'').'>すべて</a>';
        foreach(ApplicationData::LABELS as $code=>$label)$html.='<a href="'.$path.'?status='.$code.'"'.($filter===$code?' class="active" aria-current="page"':'').'>'.Security::h($label).'</a>';
        $html.='</div>';
        if($path==='/my')$html.=WorkspaceController::panel($db,$uid);
        if(!$tracks)$html.='<p class="note">まだ記録がありません。気になる企業を見つけたら、検討リストに追加してみましょう。 <a href="/companies">企業を探す</a></p>';
        else {$notices=ApplicationUpdateNotice::forUser($db,$uid,$tracks);$html.='<div class="compact-card-grid application-card-grid">';foreach($tracks as $row){$html.=self::trackCard($row,true,$notices[$row['id']]??null);}$html.='</div>';}
        if($page>1)$html.='<a href="'.$path.'?page='.($page-1).'">前へ</a> ';
        if(count($tracks)===25)$html.='<a href="'.$path.'?page='.($page+1).'">次へ</a>';
        self::view($title,$html);
    }
    private static function center(PDO $db,int $uid,array $user):void {
        $counts=ApplicationRepository::statusCounts($db,$uid);
        $html=self::head('ユーザーセンター',''.(string)$user['display_name'].'さんのペースで、ひとつずつ。').self::nav();
        $html.='<div class="status-grid">';
        foreach(ApplicationData::LABELS as $status=>$label)$html.='<a href="/applications?status='.$status.'" class="status-tile '.ApplicationData::css($status).'"><strong>'.Security::h($label).'</strong><span>'.($counts[$status]??0).'件</span></a>';
        $html.='</div><p class="note">複数の求人に応募した企業は、応募案件ごとに件数を表示します。非公開の応募記録は他のユーザーから閲覧できません。</p>';
        $html.='<p><a class="text-link" href="/contributions">自分の企業情報の修正提案 →</a></p>';
        $html.='<div class="cards"><a class="panel" href="/messages"><h2>メッセージ</h2><p>連絡・システムのお知らせを確認</p></a><a class="panel" href="/account/profile"><h2>個人資料</h2><p>アバター・表示名・ID・メール</p></a><a class="panel" href="/account/security"><h2>パスワードと安全設定</h2><p>現在のパスワードで直接変更</p></a><a class="panel" href="/my/favorites"><h2>お気に入り</h2><p>企業・仕事を分けて見る</p></a><a class="panel" href="/applications/timeline"><h2>全企業の時間軸</h2><p>面接・締切・連絡をまとめて表示</p></a></div>';
         $html.='<p><a class="btn ghost" href="/account/notifications">メール設定・検証状況</a></p>';
        $html.=ViewHistory::gallery($db,$uid);
        self::view('ユーザーセンター',$html);
    }
    private static function trackCard(array $row,bool $withForm=false,?array $notice=null):string {
        $status=ApplicationData::status($row['status']);
        $html='<article class="application-card '.ApplicationData::css($status).'">';
        if($status==='accepted')$html.='<div class="celebration-art" aria-hidden="true"><img src="/assets/celebration.svg" alt=""></div>';
        $html.='<div class="eyebrow">'.Security::h(ApplicationData::LABELS[$status]).'</div><h2><a href="/companies/'.Security::h($row['company_id']).'">'.Security::h($row['company_name']).'</a></h2>';
        if($row['job_title']!==null)$html.='<p>応募求人：'.Security::h($row['job_title']).'</p>';
        $html.=ApplicationUpdateNotice::alert($row,$notice);
        $html.='<p>'.Security::h(ApplicationData::message($status)).'</p><p><a href="/companies/'.Security::h($row['company_id']).'/timeline">企業別タイムライン →</a></p>';
        if($withForm)$html.=self::statusForm($row['company_id'],$row['job_id'],$row);
        return $html.'</article>';
    }
    private static function timeline(PDO $db,int $uid,?string $companyId=null,bool $calendar=false):void {
        $page=ApplicationData::page($_GET['page']??'1');
        $month='';$from=null;$until=null;
        if($calendar){
            $rawMonth=is_string($_GET['month']??null)?$_GET['month']:'';
            if(!preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D',$rawMonth))$rawMonth=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y-m');
            $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$rawMonth.'-01',new \DateTimeZone('Asia/Tokyo'));
            if($start===false)throw new \InvalidArgumentException('Invalid calendar month');
            $month=$rawMonth;$from=$start->format('Y-m-d');$until=$start->modify('+1 month')->format('Y-m-d');
        }
        $events=ApplicationRepository::events($db,$uid,$companyId,$calendar?150:50,$calendar?0:($page-1)*50,$calendar,$from,$until);
        $tracks=$companyId!==null?ApplicationRepository::companyTracks($db,$uid,$companyId):ApplicationRepository::tracks($db,$uid,null,100);
        $title=$calendar?'応募カレンダー':($companyId!==null?'企業別タイムライン':'全企業タイムライン');
        $html=self::head($title,'日付が未定の予定は未確定として別に表示。手動記録と公式募集情報を混同しません。').self::nav();
        $notices=ApplicationUpdateNotice::forUser($db,$uid,$tracks);
        foreach($tracks as $track)$html.=ApplicationUpdateNotice::alert($track,$notices[$track['id']]??null);
        if($calendar)$html.=self::calendarGrid($events,$month);
        if($companyId!==null&&$tracks===[])$html.='<p class="note">この企業はまだ検討リストにありません。<a href="/companies/'.$companyId.'">企業詳細に戻る</a></p>';
        if(!$events)$html.='<p class="note">まだ予定・出来事の記録はありません。まずは企業を検討リストへ追加しましょう。</p>';
        $dated=[];$undated=[];foreach($events as $e){if($e['event_on']===null)$undated[]=$e;else $dated[]=$e;}
        if($dated)$html.='<section class="section"><h2>'.($calendar?'この月の予定':'日付がある記録').'</h2><ol class="application-timeline">'.self::eventItems($dated).'</ol></section>';
        if(!$calendar&&$undated)$html.='<section class="section"><h2>日付未確定の記録</h2><ol class="application-timeline">'.self::eventItems($undated).'</ol></section>';
        if(!$calendar){$timelinePath=$companyId!==null?'/companies/'.$companyId.'/timeline':'/applications/timeline';$html.='<nav aria-label="時間軸のページ切替">';if($page>1)$html.='<a class="text-link" href="'.$timelinePath.'?page='.($page-1).'">前へ</a> ';if(count($events)===50)$html.='<a class="text-link" href="'.$timelinePath.'?page='.($page+1).'">次へ</a>';$html.='</nav>';}
        if($calendar&&count($events)===150)$html.='<p class="note">予定が150件以上あります。この月のすべてを表示できない場合があります。</p>';
        $html.='<section class="section"><h2>予定・連絡を追加する</h2>';
        if(!$tracks)$html.='<p>記録先の企業がありません。</p>';
        else {$html.='<form method="post" action="/applications/event" class="setup-form">'.Session::csrfField().'<input type="hidden" name="action" value="create"><label>企業・応募案件<select name="track_id">';
            foreach($tracks as $a)$html.='<option value="'.Security::h($a['id']).'">'.Security::h($a['company_name']).($a['job_title']?' / '.Security::h($a['job_title']):'').'</option>';
            $html.='</select></label><label>出来事の種類<select name="event_kind">';
            foreach(ApplicationData::EVENTS as $kind){if($kind==='status_change')continue;$html.='<option value="'.$kind.'">'.Security::h(self::kindLabel($kind)).'</option>';}
            $html.='</select></label><label>見出し<input name="title" maxlength="180" required></label><label>日付（未定なら空欄）<input name="event_on" type="date"></label><label>時刻（任意）<input name="event_time" type="time"></label><label>メモ<textarea name="memo" maxlength="3000" rows="4"></textarea></label><button class="btn primary">予定を保存する</button></form>';
        }
        $html.='</section>';
        self::view($title,$html);
    }
    private static function calendarGrid(array $events,string $month):string {
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',new \DateTimeZone('Asia/Tokyo'));
        if(!$dt)throw new \InvalidArgumentException('Invalid month');
        $prev=$dt->modify('-1 month')->format('Y-m');$next=$dt->modify('+1 month')->format('Y-m');
        $byDay=[];foreach($events as $event){if($event['event_on']!==null)$byDay[$event['event_on']][]=$event;}
        $html='<section class="section"><div class="calendar-nav"><a class="btn ghost" href="/applications/calendar?month='.$prev.'">前月</a><h2>'.Security::h($dt->format('Y年n月')).'</h2><a class="btn ghost" href="/applications/calendar?month='.$next.'">翌月</a></div>';
        $html.='<div class="monthly-calendar" role="group" aria-label="'.Security::h($dt->format('Y年n月')).'の予定">';
        foreach(['日','月','火','水','木','金','土'] as $weekday)$html.='<div class="weekday">'.$weekday.'</div>';
        $first=(int)$dt->format('w');$days=(int)$dt->format('t');
        for($empty=0;$empty<$first;$empty++)$html.='<div class="calendar-blank" aria-hidden="true"></div>';
        for($day=1;$day<=$days;$day++){
            $date=$dt->format('Y-m-').str_pad((string)$day,2,'0',STR_PAD_LEFT);
            $entries=$byDay[$date]??[];
            $html.='<div class="calendar-day" role="group" aria-label="'.Security::h($date).'"><strong>'.$day.'</strong>';
            foreach(array_slice($entries,0,4) as $entry)$html.='<span class="calendar-event">'.Security::h($entry['company_name']).'：'.Security::h($entry['title']).'</span>';
            if(count($entries)>4)$html.='<small>ほか'.(count($entries)-4).'件</small>';
            $html.='</div>';
        }
        $html.='</div><p class="note">カレンダーは本人専用です。日時未定の記録は <a href="/applications/timeline">全企業タイムライン</a> から確認できます。</p></section>';
        return $html;
    }
    private static function eventItems(array $events):string {
        $out='';foreach($events as $e){$title=$e['event_kind']==='status_change'?'選考状態：'.Security::h(ApplicationData::LABELS[$e['new_status']]??'変更'):Security::h($e['title']);
            $when=$e['event_on']??'日付未定';if($e['event_time']!==null)$when.=' '.substr($e['event_time'],0,5);if($e['event_kind']==='status_change')$when.='（表示日時・本人編集可能）';
            $out.='<li><time>'.Security::h($when).'</time><strong>'.Security::h($e['company_name']).'：'.$title.'</strong>';
            if($e['job_title']!==null)$out.='<span>'.Security::h($e['job_title']).'</span>';
            if($e['memo']!==null)$out.='<p>'.Security::h($e['memo']).'</p>';
            if($e['event_kind']!=='status_change')$out.='<details class="event-edit"><summary>編集・削除</summary>'.self::eventEdit($e).'</details>';
            else $out.='<details class="event-edit"><summary>表示する日付・時刻を修正</summary>'.self::statusEventDateForm($e).'</details>';
            $out.='</li>';
        }return $out;
    }
    private static function statusEventDateForm(array $e):string {
        return '<form method="post" action="/applications/event" class="setup-form">'.Session::csrfField()
            .'<input type="hidden" name="action" value="reschedule">'
            .'<input type="hidden" name="track_id" value="'.Security::h($e['track_id']).'">'
            .'<input type="hidden" name="event_id" value="'.Security::h($e['id']).'">'
            .'<input type="hidden" name="revision" value="'.(int)$e['revision'].'">'
            .'<label>表示日付<input type="date" name="event_on" value="'.Security::h($e['event_on']??'').'"></label>'
            .'<label>表示時刻<input type="time" name="event_time" value="'.Security::h(substr($e['event_time']??'',0,5)).'"></label>'
            .'<p class="note">元の選考状態と変更監査ログは維持し、表示用日時だけ更新します。</p><button class="btn primary">日時を修正</button></form>';
    }
    private static function eventEdit(array $e):string {
        $html='<form method="post" action="/applications/event" class="setup-form">'.Session::csrfField().
            '<input type="hidden" name="track_id" value="'.Security::h($e['track_id']).'">'.
            '<input type="hidden" name="event_id" value="'.Security::h($e['id']).'">'.
            '<input type="hidden" name="revision" value="'.(int)$e['revision'].'">'.
            '<label>種類<select name="event_kind">';
        foreach(ApplicationData::EVENTS as $kind)if($kind!=='status_change')$html.='<option value="'.$kind.'"'.($e['event_kind']===$kind?' selected':'').'>'.Security::h(self::kindLabel($kind)).'</option>';
        $html.='</select></label><label>見出し<input name="title" required maxlength="180" value="'.Security::h($e['title']).'"></label><label>日付<input type="date" name="event_on" value="'.Security::h($e['event_on']??'').'"></label><label>時間<input type="time" name="event_time" value="'.Security::h(substr($e['event_time']??'',0,5)).'"></label><label>メモ<textarea name="memo" maxlength="3000">'.Security::h($e['memo']??'').'</textarea></label><button type="submit" name="action" value="edit" class="btn primary">変更を保存</button><button type="submit" name="action" value="delete" class="btn ghost">この記録を削除</button></form>';
        return $html;
    }
    private static function kindLabel(string $k):string {return match($k){'deadline'=>'締切','information_session'=>'説明会','document'=>'書類提出','contact'=>'問い合わせ・連絡','interview'=>'面接','result'=>'選考結果','note'=>'メモ','other'=>'その他',default=>'状態変更'};}
    private static function nav():string {
        $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);$h='<nav class="app-subnav" aria-label="就活管理">';
        foreach(['/applications'=>'応募・検討リスト','/applications/timeline'=>'全企業タイムライン','/applications/calendar'=>'予定カレンダー','/applications/reminders'=>'任意リマインダー','/account/center'=>'ユーザーセンター','/account/notifications'=>'メール通知設定'] as $url=>$label)$h.='<a href="'.$url.'"'.($path===$url?' class="active" aria-current="page"':'').'>'.$label.'</a>';return $h.'</nav>';
    }
    private static function head(string $title,string $desc):string {return '<section class="hero compact"><span class="eyebrow">MY FUTURE / PRIVATE</span><h1>'.Security::h($title).'</h1><p>'.Security::h($desc).'</p></section>';}
    private static function view(string $title,string $html,int $code=200):void {http_response_code($code);Kernel::layout($title,$html);}
}
