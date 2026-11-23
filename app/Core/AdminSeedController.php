<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Only ADMIN/FOUNDER/DEVELOPER may review seed collisions and export/import safe history. */
final class AdminSeedController {
    public static function isRoute(string $path):bool {return in_array($path,['/admin/data/conflicts','/admin/data/entity-conflicts','/admin/data/history','/admin/data/imports','/admin/data/reviewed-reimport'],true);}
    /** Separate old company and new entity conflicts; do not confuse their auto-increment IDs. */
    public static function conflictFingerprint(PDO $db):int {
        $a=$db->query("SELECT COUNT(*),COALESCE(MAX(id),0) FROM seed_merge_conflicts WHERE status='pending'")->fetch(PDO::FETCH_NUM);
        $b=$db->query("SELECT COUNT(*),COALESCE(MAX(id),0) FROM seed_entity_conflicts WHERE status='pending'")->fetch(PDO::FETCH_NUM);
        if((int)$a[0]+(int)$b[0]===0)return 0;
        return max(1,(int)sprintf('%u',crc32(implode(':',array_merge($a,$b)))));
    }
    public static function promptIfDue(PDO $db,int $admin,string $path,string $method):bool {
        if($method!=='GET'||$admin<1||self::isRoute($path))return false;
        $marker=self::conflictFingerprint($db);if($marker===0)return false;
        $q=$db->prepare('SELECT last_shown_on,last_conflict_id FROM seed_admin_notices WHERE admin_id=?');$q->execute([$admin]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row && $row['last_shown_on']===gmdate('Y-m-d') && (int)$row['last_conflict_id']===$marker)return false;
        self::ack($db,$admin,$marker);
        $target=SeedEntityRepository::pending($db)>0?'/admin/data/entity-conflicts':'/admin/data/conflicts';
        header('Location: '.$target.'?prompt=1',true,302);return true;
    }
    public static function ack(PDO $db,int $admin,?int $marker=null):void {
        $marker??=self::conflictFingerprint($db);
        $db->prepare('INSERT INTO seed_admin_notices(admin_id,last_shown_on,last_conflict_id) VALUES (?,UTC_DATE(),?) ON DUPLICATE KEY UPDATE last_shown_on=UTC_DATE(),last_conflict_id=VALUES(last_conflict_id)')->execute([$admin,$marker]);
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array((string)$user['role'],['ADMIN','FOUNDER','DEVELOPER'],true)){self::problem(403,'アクセスできません');return;}
        $db=Database::connected();
        if($path==='/admin/data/conflicts'){self::conflicts($db,$method,(int)$user['id']);return;}
        if($path==='/admin/data/entity-conflicts'){self::entityConflicts($db,$method,(int)$user['id']);return;}
        if($path==='/admin/data/history'){self::history($db,$method,(int)$user['id']);return;}
        if($path==='/admin/data/imports'){self::imports($db,$method,(int)$user['id']);return;}
        if($path==='/admin/data/reviewed-reimport'){self::reviewedReimport($db,$method,(int)$user['id'],(string)$user['role']);return;}
        self::problem(404,'ページがありません');
    }
    private static function page():int {
        $p=$_GET['page']??'1';return is_string($p)&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$p)?min((int)$p,1000):1;
    }
    /**
     * Validate an entire visible page before beginning any write transaction.
     * Reject forged rows and inconsistent revision tokens across both conflict lists.
     * No cross-page or non-visible approval is permitted.
     * @return array<int,array{id:int,revision:int,choice:string,manual:?string}>
     */
    public static function reviewedSelections(array $pageRows,mixed $choices,mixed $revisions,mixed $manuals):array {
        if(!is_array($choices)||!is_array($revisions)||!is_array($manuals)||count($choices)>25||count($pageRows)>25){
            throw new \InvalidArgumentException('Only 25 visible conflicts may be submitted');
        }
        $visible=[];
        foreach($pageRows as $row){
            $id=$row['id']??null;
            if(!is_int($id) && (!is_string($id)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$id)))throw new \InvalidArgumentException('Invalid displayed conflict');
            $key=(string)$id;
            if(isset($visible[$key]))throw new \InvalidArgumentException('Duplicate displayed conflict');
            $visible[$key]=true;
        }
        $out=[];
        foreach($choices as $id=>$raw){
            $key=(string)$id;
            if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$key)||!isset($visible[$key])){
                throw new \InvalidArgumentException('Selection is not on the visible page');
            }
            if(!is_string($raw))throw new \InvalidArgumentException('Invalid decision type');
            $choice=SeedData::decision($raw);
            if($choice==='later')continue;
            $revision=$revisions[$id]??null;
            if(!is_string($revision)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$revision)){
                throw new \InvalidArgumentException('Invalid expected revision');
            }
            $manual=$manuals[$id]??null;
            if($manual!==null&&(!is_string($manual)||strlen($manual)>6000||str_contains($manual,"\0"))){
                throw new \InvalidArgumentException('Invalid manual value');
            }
            if($choice==='manual'&&$manual===null)throw new \InvalidArgumentException('Manual resolution needs a value');
            $out[]=['id'=>(int)$id,'revision'=>(int)$revision,'choice'=>$choice,'manual'=>$manual];
        }
        return $out;
    }
    /**
     * One visible-page decision is a single atomic unit, including audit and
     * any outbox notifications performed by repository resolvers.
     * The optional resolver is a local test seam; HTTP callers cannot supply it.
     * A failed/stale row MUST NOT leave any earlier decision committed.
     */
    public static function resolveVisiblePage(PDO $db,array $visible,mixed $choices,mixed $revisions,mixed $manuals,int $actor,bool $entities=false,?callable $resolver=null):int {
        if($actor<1)throw new \InvalidArgumentException('Invalid reviewing actor');
        if($db->inTransaction())throw new \RuntimeException('Page review needs its own transaction');
        $selected=self::reviewedSelections($visible,$choices,$revisions,$manuals);
        if(!$selected)return 0;
        $resolver??=static function(PDO $connection,int $id,int $revision,string $choice,?string $manual,int $user)use($entities):void {
            if($entities)SeedEntityRepository::resolve($connection,$id,$revision,$choice,$manual,$user);
            else SeedRepository::resolve($connection,$id,$revision,$choice,$manual,$user);
        };
        $db->beginTransaction();
        try{
            foreach($selected as $row)$resolver($db,$row['id'],$row['revision'],$row['choice'],$row['manual'],$actor);
            $db->commit();
            return count($selected);
        }catch(\Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }
    private static function conflicts(PDO $db,string $method,int $user):void {
        $page=self::page();
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem(403,'確認用トークンが無効です');return;}
            if(($_POST['confirmed']??'')!=='yes'){self::problem(422,'操作対象の確認が必要です');return;}
            try {
                self::resolveVisiblePage($db,SeedRepository::pendingPage($db,$page),
                    $_POST['choices']??null,$_POST['revisions']??null,$_POST['manuals']??[], $user);
                header('Location: /admin/data/conflicts?updated=1',true,303);return;
            }catch(\InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();self::problem(422,$e->getMessage());return;}
            catch(\Throwable $e){if($db->inTransaction())$db->rollBack();self::problem(409,'競合が変更されています。画面を再読み込みして確認してください');return;}
        }
        if($method!=='GET'){self::problem(405,'許可されない操作');return;}
        $count=SeedRepository::pending($db);self::ack($db,$user);
        $rows=SeedRepository::pendingPage($db,$page);
        $h='<section class="hero compact"><span class="eyebrow">ADMIN · SEED RECONCILIATION</span><h1>原典更新の競合を確認</h1><p>未解消：'.(int)$count.'件。1ページ25件で選択・処理します。後で処理してもヘッダーの警告は残ります。</p></section>';
        if(isset($_GET['updated']))$h.='<p class="note" role="status">選択した項目の処理を保存しました。</p>';
        if(isset($_GET['prompt']))$h.='<p class="note" role="status">未処理の原典更新があります。内容を確認してください。</p>';
        if(!$rows)$h.='<p class="note">このページに未処理の競合はありません。</p>';
        if($rows){$h.='<form method="post" action="/admin/data/conflicts?page='.$page.'" class="setup-form">'.Session::csrfField();
            foreach($rows as $r){$id=(int)$r['id'];$h.='<article class="panel"><h2>'.Security::h((string)$r['display_name']).' ／ '.Security::h((string)$r['field_key']).'</h2><p>原典：'.Security::h((string)$r['seed_key']).' ／ バージョン '.Security::h((string)$r['seed_version']).'</p><dl>';
                foreach(['old_json'=>'更新前の原典','incoming_json'=>'更新された原典','user_json'=>'ユーザー編集','effective_json'=>'現在の表示値'] as $field=>$caption){
                    $value=json_decode($r[$field],true,512,JSON_THROW_ON_ERROR);$h.='<dt>'.Security::h($caption).'</dt><dd>'.Security::h($value===null?'（空欄）':(string)$value).'</dd>';
                }$h.='</dl><input type="hidden" name="revisions['.$id.']" value="'.(int)$r['revision'].'"><label>解消方法<select name="choices['.$id.']"><option value="later">後で対応</option><option value="origin">新しい原典を採用</option><option value="user">ユーザー編集を維持</option><option value="manual">手動で調整</option></select></label><label>手動調整する値（必要なときだけ）<textarea name="manuals['.$id.']" rows="2" maxlength="6000"></textarea></label></article>';
            }
            $h.='<label><input type="checkbox" name="confirmed" value="yes" required> このページの選択した項目のみ処理することを確認しました。</label><button class="btn primary">このページの選択を保存</button></form>';
        }
        $h.='<nav aria-label="競合一覧ページ">'.($page>1?'<a href="?page='.($page-1).'">前の25件</a> ':'').(count($rows)===25?'<a href="?page='.($page+1).'">次の25件</a>':'').'</nav>';
        $h.='<a class="btn ghost" href="/admin/data">後で処理してインデックスに戻る</a>';
        Kernel::layout('原典競合',$h);return;
    }
    /** 25-row, revision-checked handling of brand/job/workplace conflicts. */
    private static function entityConflicts(PDO $db,string $method,int $actor):void {
        $page=self::page();
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem(403,'確認用トークンが無効です');return;}
            if(($_POST['confirmed']??'')!=='yes'){self::problem(422,'操作の確認が必要です');return;}
            try {
                self::resolveVisiblePage($db,SeedEntityRepository::pendingPage($db,$page),
                    $_POST['choices']??null,$_POST['revisions']??null,$_POST['manuals']??[], $actor,true);
                header('Location: /admin/data/entity-conflicts?updated=1',true,303);return;
            }catch(\InvalidArgumentException $e){if($db->inTransaction())$db->rollBack();self::problem(422,$e->getMessage());return;}
            catch(\Throwable $e){if($db->inTransaction())$db->rollBack();self::problem(409,'競合が変更されました。再読み込みして確認してください');return;}
        }
        if($method!=='GET'){self::problem(405,'GET/POST以外は許可されません');return;}
        $count=SeedEntityRepository::pending($db);self::ack($db,$actor);
        $rows=SeedEntityRepository::pendingPage($db,$page);
        $h='<section class="hero compact"><h1>ブランド・求人・勤務地の原典競合</h1><p>未解消 '.(int)$count.' 件。1ページ25件。未承認の値は自動公開しません。</p><p><a href="/admin/data/conflicts">企業基本情報の競合も確認する</a></p></section>';
        if(isset($_GET['updated']))$h.='<p class="note" role="status">解消結果を保存しました。</p>';
        if(!$rows)$h.='<p class="note">このページに未処理の競合はありません。</p>';
        if($rows){
            $h.='<form method="post" action="/admin/data/entity-conflicts?page='.$page.'" class="setup-form">'.Session::csrfField();
            foreach($rows as $r){$id=(int)$r['id'];
                $h.='<article class="panel"><h2>'.Security::h($r['record_type']).'：'.Security::h($r['field_key']).'</h2><p>原典キー：'.Security::h($r['seed_key']).' ／ レコード：'.Security::h($r['record_id']).' ／ 原典版：'.Security::h($r['seed_version']).'</p><dl>';
                foreach(['old_json'=>'前版','incoming_json'=>'新版','user_json'=>'ユーザー編集','effective_json'=>'現在の表示'] as $key=>$label){
                    $decoded=json_decode((string)$r[$key],true,512,JSON_THROW_ON_ERROR);
                    $h.='<dt>'.Security::h($label).'</dt><dd>'.Security::h($decoded===null?'（空欄）':(string)$decoded).'</dd>';
                }
                $h.='</dl><input type="hidden" name="revisions['.$id.']" value="'.(int)$r['revision'].'"><label>解消方法<select name="choices['.$id.']"><option value="later">後で対応</option><option value="origin">原典新版を採用</option><option value="user">ユーザー版を維持</option><option value="manual">手動で調整</option></select></label><label>手動値<textarea name="manuals['.$id.']" maxlength="6000" rows="2"></textarea></label></article>';
            }
            $h.='<label><input type="checkbox" name="confirmed" value="yes" required>このページの選択した項目だけ更新する</label><button class="btn primary">選択を保存</button></form>';
        }
        $h.='<nav aria-label="競合ページ">'.($page>1?'<a href="?page='.($page-1).'">前の25件</a> ':'').(count($rows)===25?'<a href="?page='.($page+1).'">次の25件</a>':'').'</nav>';
        Kernel::layout('事業データ競合',$h);
    }
    /** Read-only plan validation. Caller must validate reviewer status against DB.
     * A preview is not approval and does not prove source authenticity.
     */
    public static function reviewedPlanFromJson(string $raw,string $companyId,string $entityId,string $ticket):array {
        if(strlen($raw)<2||strlen($raw)>262144)throw new \InvalidArgumentException('JSON feed must be 1..256 KiB');
        $feed=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($feed)||array_is_list($feed))throw new \InvalidArgumentException('JSON object feed required');
        return SeedCompositeReview::plan($feed,$companyId,$entityId,$ticket);
    }
    /** Ties preview to the exact uploaded bytes, two origin batches, ticket and actor. */
    public static function reviewedPreviewDigest(string $raw,string $companyId,string $entityId,string $ticket):string {
        return hash('sha256',strlen($raw)."\n".$raw."\n".$companyId."\n".$entityId."\n".$ticket);
    }
    public static function reviewedPreviewPermitted(mixed $saved,string $digest,int $actor,int $now):bool {
        return is_array($saved)&&$actor>0&&isset($saved['digest'],$saved['actor'],$saved['expires'])
            &&is_string($saved['digest'])&&preg_match('/\A[a-f0-9]{64}\z/D',$saved['digest'])===1
            &&is_int($saved['actor'])&&$saved['actor']===$actor
            &&is_int($saved['expires'])&&$saved['expires']>=$now&&$saved['expires']<=$now+600
            &&hash_equals($saved['digest'],$digest);
    }
    /** One-time claim; clearing before a potentially failing write prevents replay.
     * Cancellation and a second preview also discard any prior authorization.
     * The token lives in the authenticated server-side session, never a form field.
     */
    public static function takeReviewedPreview(array &$state):mixed {
        $prior=$state['reviewed_seed_preview']??null;
        unset($state['reviewed_seed_preview']);
        return $prior;
    }
    /** Web review surface. Two explicit uploads are needed: inspect, then apply.
     * No unattended queue or client-supplied role may authorize this change.
     */
    private static function reviewedReimport(PDO $db,string $method,int $actor,string $role):void {
        $status='';$message='';$summaryHtml='';
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem(403,'CSRF認証に失敗しました');return;}
            $when=$_SESSION['authenticated_at']??null;
            if(!is_int($when)||$when>time()||time()-$when>900){self::problem(403,'管理者の再ログインが必要です');return;}
            if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)){self::problem(403,'権限がありません');return;}
            $stage=$_POST['stage']??null;
            // Cancel is deliberately upload-free, makes no DB changes and remains
            // available even if rate limiting has exhausted the write allowance.
            if($stage==='cancel'){
                self::takeReviewedPreview($_SESSION);
                $_SESSION['reviewed_seed_cancelled']=true;
                header('Location: /admin/data/reviewed-reimport',true,303);return;
            }
            if(!in_array($stage,['preview','apply'],true)){self::problem(422,'操作が無効です');return;}
            // Starting a new preview invalidates an old successful preview even
            // when this attempt has malformed input or a DB preflight error.
            // Applying consumes the one-use grant before any validation or DML.
            $claimed=self::takeReviewedPreview($_SESSION);
            if($stage==='preview')$claimed=null;
            if(!Throttle::allow('admin-reviewed-seed:'.$actor,6,900)){self::problem(429,'操作回数の上限です');return;}
            $info=$_FILES['feed']??null;
            if(!is_array($info)||($info['error']??null)!==UPLOAD_ERR_OK||
                !is_int($info['size']??null)||$info['size']<2||$info['size']>262144||
                !is_string($info['tmp_name']??null)||!is_uploaded_file($info['tmp_name'])){
                self::problem(422,'256KiB以内のJSONファイルを再選択してください');return;
            }
            $raw=file_get_contents($info['tmp_name']);
            $company=$_POST['company_batch_id']??null;
            $entity=$_POST['entity_batch_id']??null;
            $ticket=$_POST['review_ticket']??null;
            if(!is_string($raw)||!is_string($company)||!is_string($entity)||!is_string($ticket)||
                !in_array($stage,['preview','apply'],true)){
                self::problem(422,'入力が無効です');return;
            }
            try {
                $plan=self::reviewedPlanFromJson($raw,$company,$entity,$ticket);
                $digest=self::reviewedPreviewDigest($raw,$company,$entity,$ticket);
                if($stage==='preview'){
                    if($db->inTransaction())throw new \RuntimeException('Unexpected open transaction');
                    // Explicit read-only preflight under locks, rolled back immediately.
                    $db->beginTransaction();
                    try {
                        SeedCompositeReview::requireReviewer($db,$actor);
                        SeedCompositeReview::assertReviewed($db,$plan);
                    }finally{if($db->inTransaction())$db->rollBack();}
                    $_SESSION['reviewed_seed_preview']=['digest'=>$digest,'actor'=>$actor,'expires'=>time()+600];
                    $status='preview';
                    $message='入力と既存審査記録を検査しました。データは反映していません。再確認後、同じJSON・審査ID・チケットで実行してください。';
                    // Bounded, escaped summary so the operator can compare the
                    // intended feed to the reviewed source before a second upload.
                    $companyCount=count($plan['feed']['company_batch']['companies']??[]);
                    $entityCount=count($plan['feed']['entity_batch']['records']??[]);
                    $summaryHtml='<section class="panel"><h2>事前確認の識別情報（変更なし）</h2><dl>'
                        .'<dt>取込キー</dt><dd>'.Security::h((string)$plan['feed']['event_key']).'</dd>'
                        .'<dt>出典版</dt><dd>'.Security::h((string)($plan['feed']['company_batch']['seed_version']??'')).'</dd>'
                        .'<dt>出典URL</dt><dd>'.Security::h((string)($plan['feed']['company_batch']['source_document']??'')).'</dd>'
                        .'<dt>対象件数</dt><dd>企業'.(int)$companyCount.'件、ブランド等'.(int)$entityCount.'件</dd>'
                        .'<dt>入力ファイルSHA256</dt><dd><code>'.Security::h(hash('sha256',$raw)).'</code></dd></dl>'
                        .'<p>件数・出典・SHA256を審査記録と照合してください。この画面は差分の全面受入ではありません。</p></section>';
                }else{
                    if(($_POST['confirmed']??'')!=='yes'||!self::reviewedPreviewPermitted(
                        $claimed,$digest,$actor,time())){
                        throw new \RuntimeException('Prior same-actor same-input preview required');
                    }
                    $result=SeedCompositeRepository::applyAfterReview($db,$plan['feed'],$company,$entity,$ticket,$actor);
                    $status=(string)$result['status'];
                    $message=$status==='duplicate'?'同じ審査済み取込は処理済みです。二重の公開更新は行いませんでした。':'審査済み原典を反映しました。監査記録を確認してください。';
                }
            }catch(\InvalidArgumentException|\JsonException $e){self::problem(422,'原典JSON・審査ID・確認番号の形式が正しくありません');return;}
            catch(\Throwable $e){error_log('KMM reviewed seed operation blocked: '.get_class($e));
                self::problem(409,'審査の確定状態・出典・権限・事前確認を再確認してください。取込は完了していません。');return;}
        }elseif($method!=='GET'){header('Allow: GET, POST');self::problem(405,'GET/POST以外は許可されません');return;}
        $h='<section class="hero compact"><h1>審査済み原典の再取込</h1><p>企業・求人・勤務地の競合審査を完了した2バッチだけを対象に、必ず事前確認を行います。未審査の値を公開しません。</p></section>';
        if(($_SESSION['reviewed_seed_cancelled']??false)===true){
            unset($_SESSION['reviewed_seed_cancelled']);
            $h.='<p class="note" role="status">前回の事前確認を取り消しました。再取込は実行していません。</p>';
        }
        if($message!=='')$h.='<p class="note" role="status">'.Security::h($message).'</p>';
        $h.=$summaryHtml;
        $h.='<p class="note">アップロードしたJSONは確認後に別途再提出が必要です。事前確認は10分間、同じ管理者の同じ入力だけ有効です。実行後に自動メール送信はしません。企業更新のサイト内通知は既存のOutbox手順に従います。</p>';
        $h.='<form method="post" enctype="multipart/form-data" action="/admin/data/reviewed-reimport" class="setup-form">'.Session::csrfField()
            .'<label>審査済み企業バッチID<input name="company_batch_id" required pattern="[a-f0-9]{32}" maxlength="32"></label>'
            .'<label>審査済みエンティティバッチID<input name="entity_batch_id" required pattern="[a-f0-9]{32}" maxlength="32"></label>'
            .'<label>運営者審査番号<input name="review_ticket" required pattern="[A-Z0-9][A-Z0-9_-]{7,63}" maxlength="64"></label>'
            .'<label>複合原典フィードJSON（256KiB以下）<input type="file" name="feed" accept=".json,application/json" required></label>'
            .'<label><input type="checkbox" name="confirmed" value="yes">前回確認した同一内容を、審査済みバッチに基づいて反映することを承認</label>'
            .'<div class="actions"><button class="btn ghost" type="submit" name="stage" value="preview">事前確認（変更なし）</button> '
            .'<button class="btn primary" type="submit" name="stage" value="apply">同一内容を再提出して反映</button></div></form>';
        $h.='<form method="post" action="/admin/data/reviewed-reimport" class="setup-form">'.Session::csrfField()
            .'<button class="btn ghost" type="submit" name="stage" value="cancel">前回の事前確認を取り消す（変更なし）</button></form>';
        $h.='<p><a class="text-link" href="/admin/data/conflicts">企業の競合審査へ</a> ／ <a class="text-link" href="/admin/data/entity-conflicts">ブランド・求人・勤務地の審査へ</a></p>';
        Kernel::layout('審査済み原典の再取込',$h);
    }
    private static function history(PDO $db,string $method,int $admin):void {
        if($method!=='GET'){self::problem(405,'操作は許可されません');return;}
        if(isset($_GET['export'])){
            try{$format=HistoryTransfer::format((string)$_GET['export']);$bytes=HistoryTransfer::export($db,$format,500);
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')->execute([$admin,'history.exported','history_export',$format,SeedData::encode(['limit'=>500,'bytes'=>strlen($bytes)])]);
                header('Content-Type: '.($format==='csv'?'text/csv':'application/x-ndjson').'; charset=utf-8');
                header('Content-Disposition: attachment; filename="kmm-history-redacted.'.$format.'"');
                header('X-Content-Type-Options: nosniff');echo $bytes;
            }catch(\Throwable){self::problem(422,'エクスポートの条件を確認してください');}return;
        }
        $h='<section class="hero compact"><h1>変更履歴の安全な書き出し</h1><p>直近500件の変更メタデータを出力します。個人情報・監査の内部詳細・認証情報は出力しません。CSVは数式対策を適用します。</p></section><p><a class="btn primary" href="?export=jsonl">JSONLを取得</a> <a class="btn ghost" href="?export=csv">CSVを取得</a></p><p><a class="text-link" href="/admin/data/imports">安全な履歴の取込み・事前確認</a></p>';
        Kernel::layout('変更履歴のエクスポート',$h);
    }
    private static function imports(PDO $db,string $method,int $actor):void {
        $notice='';
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem(403,'CSRF認証に失敗しました');return;}
            if(!isset($_FILES['history'])||!is_array($_FILES['history'])||($_FILES['history']['error']??null)!==UPLOAD_ERR_OK){self::problem(422,'取込みファイルを選択してください');return;}
            $info=$_FILES['history'];if(($info['size']??0)>512*1024||!is_uploaded_file((string)$info['tmp_name'])){self::problem(422,'サイズ上限またはファイルを確認してください');return;}
            $raw=file_get_contents((string)$info['tmp_name']);$format=$_POST['format']??'';
            try{
                if(!is_string($raw)||!is_string($format))throw new \InvalidArgumentException('Invalid upload');
                $rows=HistoryTransfer::preview($raw,$format);
                $digest=hash('sha256',$format."\n".$raw);
                if(($_POST['commit']??'')==='yes'){
                    $previous=$_SESSION['history_preview']??null;
                    if(!HistoryTransfer::previewPermitted($previous,$digest,$actor,time()))throw new \RuntimeException('同一管理者が同じファイルを事前確認してください');
                    unset($_SESSION['history_preview']);$result=HistoryTransfer::append($db,$raw,$format,$actor);
                    $notice=$result['status']==='duplicate'?'同じ履歴は既に取り込み済みです。':$result['count'].'件を別枠の参考履歴へ登録しました。既存監査・企業データは変更していません。';
                }else{
                    $_SESSION['history_preview']=['digest'=>$digest,'actor'=>$actor,'until'=>time()+600];
                    $notice=count($rows).'件を検証しました。反映する場合は、同じファイルを再選択し「事前確認後に登録」を選択してください。';
                }
            }catch(\InvalidArgumentException $e){self::problem(422,$e->getMessage());return;}
            catch(\Throwable $e){self::problem(409,'取込みに失敗しました。条件を確認して再実行してください');return;}
        }elseif($method!=='GET'){self::problem(405,'許可されない操作です');return;}
        $h='<section class="hero compact"><h1>変更履歴の取込み</h1><p>CSV・JSONLのみ。512KB／1000件まで。必ずドライランし、次に再アップロードして確定します。インポートした履歴は「参考データ」に隔離され、過去の監査ログや企業本体を上書きしません。</p></section>';
        if($notice!=='')$h.='<p class="note" role="status">'.Security::h($notice).'</p>';
        $h.='<form method="post" enctype="multipart/form-data" action="/admin/data/imports" class="setup-form">'.Session::csrfField().'<label>ファイル（最大512KB）<input type="file" name="history" accept=".jsonl,.csv" required></label><label>形式<select name="format"><option value="jsonl">JSONL</option><option value="csv">CSV</option></select></label><label><input type="checkbox" name="commit" value="yes"> 事前確認済みの同一ファイルを参考履歴へ登録する</label><button class="btn primary">検査／登録</button></form>';
        Kernel::layout('安全な履歴の取込み',$h);
    }
    private static function problem(int $status,string $message):void {http_response_code($status);Kernel::layout('操作できません','<section class="hero compact"><h1>確認が必要です</h1><p>'.Security::h($message).'</p></section>',false);}
}
