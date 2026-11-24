<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * Authenticated ADMIN-only research evidence *preflight*.
 * Deliberately has no approval, seed import, company change, public read,
 * user-overlay edit, email or notification route. Audit writes metadata only.
 */
final class AdminResearchIdentityController {
    private const PATH='/admin/data/research-identities';
    // Independently pinned d1.0.1 research release; a user-uploaded manifest
    // cannot redefine the trusted SHA256 anchors for this application version.
    private const RESEARCH_MANIFEST_SHA256='d85f1a8063fc4093173f9c0a06d3257f40e463d918ac48a5394235219a32ad2d';
    private const CATALOGUE_SHA256='e274f9df5739d813c46089e866cf561815078c3ff45d93fda22affcbe54559d1';
    public static function isRoute(string $path):bool{return $path===self::PATH;}
    public static function requestPermitted(string $role,int $actor,int $authenticatedAt,bool $csrfValid,int $now):bool {
        return in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true) && $actor>0 && $csrfValid
            && $authenticatedAt>0 && $authenticatedAt<=$now && $now-$authenticatedAt<=900;
    }
    public static function verifiedInput(array $parts,string $catalogueBytes):array {
        foreach(['manifest','updates','report','evidence'] as $name){
            if(!isset($parts[$name])||!is_string($parts[$name])||strlen($parts[$name])<2
                ||strlen($parts[$name])>262144)throw new \DomainException('Missing or oversized source');
        }
        if(!hash_equals(self::RESEARCH_MANIFEST_SHA256,hash('sha256',$parts['manifest'])))
            throw new \DomainException('Research manifest is not the pinned d1.0.1 release');
        if(!hash_equals(self::CATALOGUE_SHA256,hash('sha256',$catalogueBytes)))
            throw new \DomainException('Server seed catalogue is not the pinned source');
        $catalogue=json_decode($catalogueBytes,true,128,JSON_THROW_ON_ERROR);
        $evidence=json_decode($parts['evidence'],true,128,JSON_THROW_ON_ERROR);
        if(!is_array($catalogue)||!is_array($evidence)||array_is_list($evidence))
            throw new \DomainException('Expected JSON evidence object');
        $review=ResearchDeltaReview::review($parts['manifest'],$parts['updates'],$parts['report']);
        $plan=ResearchIdentityReconciliation::plan($review,$catalogue);
        $result=ResearchIdentityEvidence::preflight($plan,$catalogue,$evidence);
        if(($result['counts']['research_records']??null)!==27 ||($result['counts']['approved']??null)!==0
            ||($result['counts']['published']??null)!==0 ||($result['counts']['notification_queued']??null)!==0
            ||($result['may_approve']??null)!==false ||($result['may_publish']??null)!==false
            ||($result['may_notify']??null)!==false ||($result['database_written']??null)!==false)
            throw new \DomainException('Evidence preflight must never approve or publish');
        return $result;
    }
    /** Audit-only write with fresh, versioned actor checked under row lock. */
    public static function auditPreview(PDO $db,array $actor,int $sessionVersion,array $preflight,string $evidenceHash):void {
        $id=(int)($actor['id']??0);$role=$actor['role']??null;
        if(!is_string($role)||!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$id<1
            ||$sessionVersion<0||($preflight['counts']['research_records']??null)!==27
            ||($preflight['counts']['approved']??null)!==0
            ||($preflight['counts']['published']??null)!==0
            ||($preflight['counts']['notification_queued']??null)!==0
            ||($preflight['may_approve']??null)!==false
            ||!is_string($evidenceHash)||preg_match('/\A[a-f0-9]{64}\z/D',$evidenceHash)!==1)
            throw new \DomainException('Invalid unauthorised preflight audit');
        if($db->inTransaction())throw new \RuntimeException('Nested preflight audit');
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT u.status,u.auth_version,ur.role_code AS role FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? FOR UPDATE');
            $q->execute([$id]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($current)||($current['status']??null)!=='active'
                ||($current['role']??null)!==$role||(int)($current['auth_version']??-1)!==$sessionVersion)
                throw new \DomainException('Administrator disabled, stale or role changed');
            // No URL, recruiter contact, research notes or personal applicant data in audit.
            $details=json_encode(['research_version'=>'d1.0.1','submitted'=>(int)($preflight['counts']['evidence_proposals']??0),
                'unapproved'=>27,'upload_sha256'=>$evidenceHash,'result'=>'preflight_only'],JSON_THROW_ON_ERROR);
            $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                ->execute([$id,'research.identity.preflight','research_delta','d1.0.1',$details]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Input is a *real* HTTP upload. Local tests exercise verifiedInput directly. */
    private static function uploaded(mixed $file):string {
        if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK
            ||!is_int($file['size']??null)||$file['size']<2||$file['size']>262144
            ||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))
            throw new \InvalidArgumentException('Upload requires a bounded regular HTTP file');
        $bytes=file_get_contents($file['tmp_name']);
        if(!is_string($bytes)||strlen($bytes)!==$file['size'])throw new \RuntimeException('Incomplete upload');
        return $bytes;
    }
    public static function handle(string $method,array $user):void {
        $role=(string)($user['role']??'');$id=(int)($user['id']??0);
        if(!in_array($role,['ADMIN','FOUNDER','DEVELOPER'],true)||$id<1){self::error(403,'管理者権限が必要です');return;}
        if($method!=='GET'&&$method!=='POST'){header('Allow: GET, POST');self::error(405,'GET/POSTのみ許可');return;}
        $notice='';$summary='';
        if($method==='POST'){
            if(!self::requestPermitted($role,$id,(int)($_SESSION['authenticated_at']??0),
                is_string($_POST['csrf']??null)&&Session::validCsrf($_POST['csrf']),time())){
                self::error(403,'最近の再ログインと正しいCSRFトークンが必要です');return;
            }
            if(($_POST['stage']??null)==='cancel'){
                unset($_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates'],
                    $_SESSION['research_identity_comparison_snapshot'],$_SESSION['research_identity_snapshot_secret']);
                header('Location: '.self::PATH.'?cancelled=1',true,303);return;
            }
            if(($_POST['stage']??null)==='private_atomic_import'){
                // Only a private durable release stage; no business-field projection or publication.
                // Authorisation, CSRF and recent reauthentication were checked above.
                try {
                    if(!Throttle::allow('research-private-import:'.$id,4,900)){
                        self::error(429,'取込の操作回数上限です');return;
                    }
                    $feed=json_decode(self::uploaded($_FILES['accepted_feed']??null),true,128,JSON_THROW_ON_ERROR);
                    $part=$_FILES['verified_id_bindings']??null;
                    $bindings=$part===null||($part['error']??null)===UPLOAD_ERR_NO_FILE
                        ?[]:json_decode(self::uploaded($part),true,128,JSON_THROW_ON_ERROR);
                    $eventKey=$_POST['event_key']??null;
                    if(!is_string($eventKey))throw new \DomainException('An explicit stable operation key is required');
                    $path=dirname(__DIR__,2).'/database/seeds_company_2026-11-18.json';
                    if(!is_file($path)||is_link($path))throw new \DomainException('No trusted site seed catalogue');
                    $catalogue=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
                    $result=ResearchImportAtomic::apply(Database::connected(),$feed,$catalogue,$bindings,
                        $user,(int)($_SESSION['auth_version']??-1),$eventKey);
                    // Do not display DB IDs, overlay values, source records or actual business data.
                    $notice='非公開の研究取込：'.Security::h($result['status']).'／'.(int)$result['records_staged']
                        .'件／明示した技術ID対応'.(int)$result['technical_bindings']
                        .'件。既存企業・求人・本人編集・公開・通知は変更していません。';
                }catch(\DomainException|\InvalidArgumentException|\JsonException){
                    self::error(422,'非公開取込の原典・技術的ID対応・操作キーが無効です');return;
                }catch(\Throwable $e){error_log('Private research import failed: '.get_class($e));
                    self::error(409,'取込結果が確定できません。バッチの監査結果を照会し、同じ操作キーで再試行してください');return;}
            }else             if(($_POST['stage']??null)==='technical_mapping_plan'){
                try {
                    if(!Throttle::allow('research-technical-plan:'.$id,12,900)){
                        self::error(429,'技術的取込計画の操作回数上限です');return;
                    }
                    $feed=json_decode(self::uploaded($_FILES['accepted_feed']??null),true,128,JSON_THROW_ON_ERROR);
                    $bindingFile=$_FILES['technical_bindings']??null;
                    // One verified feed is sufficient: no repeated research-source uploads.
                    $bindings=$bindingFile===null||($bindingFile['error']??null)===UPLOAD_ERR_NO_FILE
                        ?[]:json_decode(self::uploaded($bindingFile),true,128,JSON_THROW_ON_ERROR);
                    $path=dirname(__DIR__,2).'/database/seeds_company_2026-11-18.json';
                    if(!is_file($path)||is_link($path))throw new \DomainException('Seed catalogue missing');
                    $catalogue=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
                    $db=Database::connected();
                    $plan=ResearchTechnicalMapping::plan($feed,$catalogue,$bindings);
                    $live=ResearchTechnicalMapping::inspectLinkedCandidates($db,$user,(int)($_SESSION['auth_version']??-1),$feed,$catalogue,$bindings);
                    $verifiedKeys=[];
                    foreach($live['links'] as $linked) $verifiedKeys[$linked['review_id']]=$linked;
                    $counts=$plan['counts'];
                    $notice='情報収集確定版の取込計画（未反映）：'.(int)$counts['accepted'].'件、HOLD'.(int)$counts['hold']
                        .'件、除外'.(int)$counts['excluded'].'件、既存seedへの技術的提案'.(int)$counts['proposed_seed_links'].'件。';
                    $summary='<section class="panel"><h2>確定研究データ27件の非公開・技術的ID対応プレビュー</h2>'
                        .'<p>調査内容の再確認は不要。技術的な名前空間、明示的なseed対応候補、研究HOLD/Xの保持を検査しました。'
                        .'実DBの会社同定・情報反映・公開・通知は行っていません。</p>'
                        .'<div class="table-scroll"><table><thead><tr><th>研究ID</th><th>取込先の非公開キー</th><th>技術的区分</th><th>既存seed候補</th></tr></thead><tbody>';
                    foreach($plan['items'] as $row){
                        $summary.='<tr><td><code>'.Security::h($row['review_id']).'</code></td>'
                            .'<td><code>'.Security::h($row['private_stage_key']).'</code></td>'
                            .'<td>'.Security::h($row['technical_status']).'</td>'
                            .'<td>'.Security::h($row['proposed_seed_key']??'—').(isset($verifiedKeys[$row['review_id']])?'（DB内の既存seedあり・同一法人認定なし）':'').'</td></tr>';
                    }
                    $summary.='</tbody></table></div><p>本人Overlayの詳細値は表示しません。既存seed候補は実ID確定ではなく、後続のロック付き比較が必要です。</p></section>';
                }catch(\DomainException|\JsonException|\InvalidArgumentException){
                    self::error(422,'確定フィード、技術的seed指定、権限または原典ハッシュが一致しません');return;
                }catch(\Throwable $e){error_log('Research technical plan unavailable: '.get_class($e));
                    self::error(409,'技術的取込プレビューを実行できません。サイト情報は変更していません');return;}
            }else if(($_POST['stage']??null)==='compare_seed_draft'){
                unset($_SESSION['research_identity_comparison_snapshot']);
        $preview=$_SESSION['research_identity_preview']??null;
                $tasks=$_SESSION['research_identity_tasks']??null;
                $seedCandidates=$_SESSION['research_identity_seed_candidates']??null;
                $reviewId=$_POST['review_id']??null;$nonce=$_POST['nonce']??null;$rev=$_POST['expected_revision']??null;
                if(!is_string($reviewId)||!is_array($tasks)||!isset($tasks[$reviewId])
                   ||!is_array($seedCandidates)||!isset($seedCandidates[$reviewId])
                   ||!is_string($nonce)||!is_array($preview)||!is_string($preview['nonce']??null)
                   ||!hash_equals($preview['nonce'],$nonce)
                   ||!is_string($rev)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$rev)){
                    self::error(409,'比較対象・セッションまたは改訂番号が無効です');return;
                }
                try{
                    $draft=self::uploaded($_FILES['comparison_draft']??null);
                    $incoming=json_decode($draft,true,16,JSON_THROW_ON_ERROR);
                    $result=ResearchLiveThreeWayPreview::inspect(Database::connected(),$user,
                        (int)($_SESSION['auth_version']??-1),$preview,$tasks[$reviewId],
                        $seedCandidates,$seedCandidates[$reviewId],$incoming,(int)$rev,time());
                    $secret=$_SESSION['research_identity_snapshot_secret']??null;
                    if(!is_string($secret)||preg_match('/\A[a-f0-9]{64}\z/D',$secret)!==1)
                        throw new \DomainException('Server review snapshot session is invalid');
                    $snapshot=ResearchReviewSnapshot::issue($user,(int)$_SESSION['auth_version'],
                        $preview,$tasks[$reviewId],$seedCandidates[$reviewId],$incoming,$result,(int)$rev,time(),$secret);
                    $_SESSION['research_identity_comparison_snapshot']=$snapshot;
                    $summary='<section class="panel"><h2>実DBとの3者比較（未承認の試算）</h2>'
                        .'<p>これは運営者が入力した仮の新原典と、指定された既存seedの状態を比較する診断であり、実法人ID・求人募集の確認ではありません。</p>'
                        .'<dl><dt>判定</dt><dd>'.Security::h($result['status']).'</dd>'
                        .'<dt>候補変更項目</dt><dd>'.Security::h(implode(', ',$result['candidate_field_keys'])).'</dd>'
                        .'<dt>競合項目</dt><dd>'.Security::h(implode(', ',$result['conflict_field_keys'])).'</dd></dl>'
                        .'<p>比較時点ID: <code>'.Security::h(substr($snapshot['fingerprint'],0,16)).'</code>（10分以内の読取り時点の参考記録であり、DBの最新状態や法人同定を証明しません）</p>'
                        .'<p>個人の編集内容・元の値・提出した値は表示しません。承認・更新・公開・通知はできません。</p></section>';
                }catch(\InvalidArgumentException|\DomainException|\JsonException){
                    self::error(409,'比較の権限、根拠、DB版または入力内容が一致しません。再確認してください');return;
                }catch(\Throwable $e){error_log('Research live draft comparison blocked: '.get_class($e));
                    self::error(409,'3者比較を完了できません。実DBを再照合してください');return;}
            }else if(in_array(($_POST['stage']??null),['review_item','review_item_status'],true)){
                $preview=$_SESSION['research_identity_preview']??null;
                $tasks=$_SESSION['research_identity_tasks']??null;
                $reviewId=$_POST['review_id']??null;
                $nonce=$_POST['nonce']??null;
                $now=time();
                if(!ResearchIdentityReturn::validPreview($preview,$id,(int)($_SESSION['auth_version']??-1),$now)
                    ||!is_string($nonce)||!hash_equals($preview['nonce'],$nonce)
                    ||!is_array($tasks)||!is_string($reviewId)||!isset($tasks[$reviewId])
                    ||!ResearchIdentityReviewState::validTask($tasks[$reviewId])){
                    self::error(409,'事前検査が期限切れか、審査項目が変更されました。再検査してください');return;
                }
                try {
                    $db=Database::connected();
                    if(($_POST['stage']??null)==='review_item_status'){
                        $state=ResearchIdentityReviewState::read($db,$user,(int)$_SESSION['auth_version'],$preview,$tasks[$reviewId],$now);
                    }else{
                        $revision=$_POST['expected_revision']??null;
                        if(!is_string($revision)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$revision))
                            throw new \DomainException('Invalid expected revision');
                        $target=$_POST['target_status']??null;
                        $reason=$_POST['reason']??null;
                        if(!is_string($target)||($reason!==null&&!is_string($reason)))
                            throw new \DomainException('Invalid review status');
                        $state=ResearchIdentityReviewState::transition($db,$user,(int)$_SESSION['auth_version'],$preview,
                            $tasks[$reviewId],(int)$revision,$target,$target==='needs_evidence'?$reason:null,$now);
                    }
                    $notice='項目 '.Security::h($reviewId).' は '.Security::h($state['state']).'、改訂番号 '.(int)$state['revision']
                        .'（未承認、非公開）。次の変更時はこの改訂番号を入力してください。';
                }catch(\DomainException $e){self::error(409,'審査状態の競合・期限切れ・提出根拠の不一致があります。状態を照会してください');return;}
                catch(\Throwable $e){error_log('Research identity item review blocked: '.get_class($e));
                    self::error(409,'審査記録の成否が不明です。先に項目の状態照会を実行してください');return;}
            }else if(in_array(($_POST['stage']??null),['return','return_status'],true)){
                unset($_SESSION['research_identity_comparison_snapshot']);
                $preview=$_SESSION['research_identity_preview']??null;
                $nonce=$_POST['nonce']??null;
                $now=time();
                if(!ResearchIdentityReturn::validPreview($preview,$id,(int)($_SESSION['auth_version']??-1),$now)
                    ||!is_string($nonce)||!hash_equals($preview['nonce'],$nonce)){
                    self::error(409,'事前検査が期限切れまたは変更されています。再検査してください');return;
                }
                try{
                    $db=Database::connected();
                    if(($_POST['stage']??null)==='return_status'){
                        $returned=ResearchIdentityReturn::status($db,$user,(int)$_SESSION['auth_version'],$preview,$now);
                        if($returned){unset($_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates'],
                    $_SESSION['research_identity_comparison_snapshot'],$_SESSION['research_identity_snapshot_secret']);
                            header('Location: '.self::PATH.'?returned=1',true,303);return;}
                        $notice='差戻し監査は未確認です。事前検査を維持しています。';
                    }else{
                        $reason=$_POST['reason']??null;
                        if(!is_string($reason))throw new \DomainException('Invalid return reason');
                        ResearchIdentityReturn::record($db,$user,(int)$_SESSION['auth_version'],$preview,$reason,$now);
                        unset($_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates'],
                    $_SESSION['research_identity_comparison_snapshot'],$_SESSION['research_identity_snapshot_secret']);
                        header('Location: '.self::PATH.'?returned=1',true,303);return;
                    }
                }catch(\DomainException){self::error(422,'差戻し理由または管理者セッションが不正です');return;}
                catch(\Throwable $e){error_log('Research identity return blocked: '.get_class($e));
                    self::error(409,'差戻し監査の結果が不明です。「差戻し状態を照会」を実行してください');return;}
            }else{
                unset($_SESSION['research_identity_preview'],$_SESSION['research_identity_tasks'],$_SESSION['research_identity_seed_candidates'],
                    $_SESSION['research_identity_comparison_snapshot'],$_SESSION['research_identity_snapshot_secret']);
                if(($_POST['stage']??null)!=='preflight'){self::error(422,'承認・反映操作はこの画面に存在しません');return;}
            if(!Throttle::allow('research-identity-preflight:'.$id,6,900)){self::error(429,'事前確認の回数上限です');return;}
            try{
                $parts=[];
                foreach(['manifest','updates','report','evidence'] as $key)$parts[$key]=self::uploaded($_FILES[$key]??null);
                $seedFile=dirname(__DIR__,2).'/database/seeds_company_2026-11-18.json';
                if(!is_file($seedFile)||is_link($seedFile))throw new \RuntimeException('Server catalogue unavailable');
                $catalogueBytes=file_get_contents($seedFile);
                if(!is_string($catalogueBytes))throw new \RuntimeException('Server catalogue unreadable');
                $result=self::verifiedInput($parts,$catalogueBytes);
                self::auditPreview(Database::connected(),$user,(int)($_SESSION['auth_version']??-1),$result,
                    hash('sha256',$parts['evidence']));
                $submitted=(int)$result['counts']['evidence_proposals'];
                unset($_SESSION['research_identity_comparison_snapshot']);
                $_SESSION['research_identity_snapshot_secret']=bin2hex(random_bytes(32));
                $_SESSION['research_identity_tasks']=ResearchIdentityReviewState::trustedTasks($result);
                $_SESSION['research_identity_seed_candidates']=ResearchLiveThreeWayPreview::trustedCandidates($result);
                $_SESSION['research_identity_preview']=ResearchIdentityReturn::newPreview(
                    $id,(int)($_SESSION['auth_version']??-1),hash('sha256',$parts['evidence']),
                    (string)$result['operator_ticket'],$submitted,time());
                $notice=$submitted.'件の根拠候補を形式検証し、監査しました。正式同定・公開・通知はいずれも0件です。';
                $summary='<section class="panel"><h2>事前確認結果（確定なし）</h2><dl>'
                    .'<dt>調査</dt><dd>d1.0.1／27件</dd><dt>根拠候補</dt><dd>'.(int)$submitted.'件</dd>'
                    .'<dt>審査チケット</dt><dd>'.Security::h((string)$result['operator_ticket']).'</dd>'
                    .'<dt>提出ファイルSHA256</dt><dd><code>'.Security::h(hash('sha256',$parts['evidence'])).'</code></dd>'
                    .'</dl><p>候補の提出は正式な承認・求人受付確認ではありません。</p></section>';
            }catch(\InvalidArgumentException|\DomainException|\JsonException){self::error(422,'原典・出典・確認用JSONを再照合してください');return;}
            catch(\Throwable $e){error_log('Research identity preflight blocked: '.get_class($e));
                self::error(409,'管理者状態または監査の更新に失敗しました。企業データは変更していません');return;}
            }
        }
        if(isset($_GET['cancelled']))$notice='前回の事前確認表示を取り消しました。企業情報は変更していません。';
        if(isset($_GET['returned']))$notice='根拠候補を監査上で差戻しました。企業・求人・通知は変更していません。';
        $html='<section class="hero compact"><h1>企業同定の根拠・承認前確認</h1><p>情報収集 d1.0.1 の27件は調査担当の確定情報としてそのまま利用します。ここでは原典の事実を再調査せず、サイト内の企業・ブランド・求人・勤務地IDと本人編集の衝突のみ技術的に確認します。企業名の一致だけでは実DBと自動結合しません。</p></section>';
        if($notice!=='')$html.='<p role="status" class="note">'.Security::h($notice).'</p>';
        $html.=$summary;
        // Read-only item history. Each request independently rechecks the DB actor
        // and validates contiguous revisions; malformed histories are not shown.
        if(isset($_GET['history_id'])){
            try{
                $historyId=$_GET['history_id'];
                if(!is_string($historyId))throw new \DomainException('Bad review ID');
                $page=ResearchIdentityReviewBoard::page($_GET['history_page']??'1');
                $history=ResearchIdentityReviewHistory::listing(Database::connected(),$user,
                    (int)($_SESSION['auth_version']??-1),$historyId,$page);
                $html.='<section class="panel"><h2>項目別変更履歴（内部監査・未承認）</h2>'
                    .'<p>研究ID <code>'.Security::h($historyId).'</code> ／ 現在の改訂 '.(int)$history['current_revision']
                    .'。原典・本人編集・求人・URLの内容は表示しません。</p>';
                if($history['events']===[])$html.='<p>このページに変更履歴はありません。</p>';
                else{
                    $html.='<div class="table-scroll"><table><thead><tr><th>改訂</th><th>状態</th><th>理由</th><th>担当者ID</th></tr></thead><tbody>';
                    foreach($history['events'] as $event){
                        $html.='<tr><td>'.(int)$event['to_revision'].'</td><td>'.Security::h($event['state'])
                            .'</td><td>'.Security::h((string)($event['reason_code']??'—'))
                            .'</td><td>'.(int)$event['actor_id'].'</td></tr>';
                    }
                    $html.='</tbody></table></div>';
                }
                $url=self::PATH.'?history_id='.rawurlencode($historyId).'&history_page=';
                $html.='<p>履歴ページ '.(int)$page.'：';
                if($page>1)$html.='<a href="'.Security::h($url.($page-1)).'">前へ</a> ';
                if($history['has_next'])$html.='<a href="'.Security::h($url.($page+1)).'">次へ</a>';
                $html.='</p></section>';
            }catch(\Throwable $e){
                error_log('Private research audit history blocked: '.get_class($e));
                $html.='<p role="alert">項目別の監査履歴を検証できません。改訂の欠落・権限失効を確認してください。</p>';
            }
        }

        // Shared, read-only reviewer queue: the reader is re-authorised using the
        // current database status/role/auth_version. No preview or approval token.
        try{
            $queuePage=ResearchIdentityReviewBoard::page($_GET['page']??'1');
            $queue=ResearchIdentityReviewBoard::listing(Database::connected(),$user,
                (int)($_SESSION['auth_version']??-1),$queuePage);
            $html.='<section class="panel"><h2>管理者共通の内部審査キュー（未承認）</h2>'
                .'<p>別担当者の状態を読取り専用で共有します。状態は実法人・求人IDの確認結果ではなく、公開や通知も行いません。</p>';
            if($queue['items']===[]){$html.='<p>このページには審査記録がありません。</p>';}
            else{
                $html.='<div class="table-scroll"><table><thead><tr><th>研究レコード</th><th>内部状態</th><th>理由</th><th>改訂</th><th>最終担当者ID</th><th>内部履歴</th></tr></thead><tbody>';
                foreach($queue['items'] as $item){
                    $html.='<tr><td><code>'.Security::h($item['review_id']).'</code></td><td>'.Security::h($item['state'])
                        .'</td><td>'.Security::h((string)($item['reason_code']??'—'))
                        .'</td><td>'.(int)$item['revision'].'</td><td>'.(int)$item['last_actor_id']
                        .'</td><td><a href="'.self::PATH.'?history_id='.rawurlencode($item['review_id']).'&amp;history_page=1">履歴</a></td></tr>';
                }
                $html.='</tbody></table></div>';
            }
            $html.='<p>ページ '.(int)$queue['page'].'：';
            if($queue['page']>1)$html.='<a href="'.self::PATH.'?page='.($queue['page']-1).'">前へ</a> ';
            if($queue['has_next'])$html.='<a href="'.self::PATH.'?page='.($queue['page']+1).'">次へ</a>';
            $html.='</p></section>';
        }catch(\Throwable $e){
            error_log('Private research review queue blocked: '.get_class($e));
            $html.='<p role="alert">内部審査キューを取得できません。権限・DB状態を確認してください。承認・公開は行っていません。</p>';
        }
        $preview=$_SESSION['research_identity_preview']??null;
        if(ResearchIdentityReturn::validPreview($preview,$id,(int)($_SESSION['auth_version']??-1),time())){
            $token=Security::h($preview['nonce']);
            $tasks=$_SESSION['research_identity_tasks']??[];
            $seedCandidates=$_SESSION['research_identity_seed_candidates']??[];
            if(is_array($seedCandidates)&&$seedCandidates!==[]){
                $html.='<section class="panel"><h2>既存企業seedと3者比較（読取り・仮説のみ）</h2>'
                    .'<p>本人編集を保護するため、既存原典・本人Overlay・現在のDB値を、アップロードした仮の候補値と比較します。**会社の実同定・承認ではありません。**</p>';
                $html.='<form method="post" enctype="multipart/form-data" action="'.self::PATH.'">'.Session::csrfField()
                    .'<input type="hidden" name="nonce" value="'.$token.'">'
                    .'<input type="hidden" name="stage" value="compare_seed_draft">'
                    .'<label>名称候補の研究レコード<select name="review_id" required>';
                foreach($seedCandidates as $reviewId=>$seed){
                    $html.='<option value="'.Security::h($reviewId).'">'.Security::h($reviewId).' （未確認seed候補：'.Security::h($seed).'）</option>';
                }
                $html.='</select></label><label>想定する企業revision（DBで要事前確認）<input type="number" name="expected_revision" min="0" max="2147483646" value="0" required></label>'
                    .'<label>仮の新原典企業スナップショット（display_name/legal_name/description/website_urlの4項目JSON）<input type="file" name="comparison_draft" accept=".json,application/json" required></label>'
                    .'<button class="btn ghost" type="submit">読取り専用で競合を検査（承認しない）</button></form></section>';
            }
            if(is_array($tasks)&&$tasks!==[]){
                $html.='<section class="panel"><h2>項目別の内部審査状態（未承認）</h2>'
                    .'<p>実際に提出済みの根拠候補だけを選択できます。状態変更は監査と同じDBトランザクションで保存し、会社・求人・ユーザー編集には反映しません。各変更前に現在の改訂番号を照会してください。</p>';
                $html.='<form method="post" action="'.self::PATH.'">'.Session::csrfField()
                    .'<input type="hidden" name="nonce" value="'.$token.'">'
                    .'<label>審査する研究レコード<select name="review_id" required>';
                foreach($tasks as $task){$safe=Security::h((string)$task['review_id']);
                    $html.='<option value="'.$safe.'">'.$safe.'</option>';}
                $html.='</select></label><label>現在の改訂番号（初回0）<input type="number" name="expected_revision" min="0" max="2147483646" value="0" required></label>'
                    .'<label>内部審査状態<select name="target_status">'
                    .'<option value="evidence_review">根拠の独立確認待ち（正式承認ではない）</option>'
                    .'<option value="needs_evidence">項目別に追加根拠を要求</option>'
                    .'</select></label><label>追加根拠が必要な理由（状態変更時のみ）<select name="reason">'
                    .'<option value="missing_evidence">一次根拠不足</option><option value="identity_unverified">法人・ブランドの同一性未確認</option>'
                    .'<option value="worksite_unverified">実勤務地未確認</option><option value="vacancy_unverified">採用残枠未確認</option>'
                    .'<option value="overlay_conflict">本人Overlayとの競合</option></select></label>'
                    .'<button class="btn ghost" type="submit" name="stage" value="review_item_status">選択項目の現在状態・改訂番号を照会</button>'
                    .'<button class="btn ghost" type="submit" name="stage" value="review_item">項目別審査状態を保存（未承認）</button></form></section>';
            }
            $html.='<section class="panel"><h2>根拠確認を差戻す（承認ではありません）</h2>'
                .'<p>監査のみ記録します。会社・ブランド・求人・勤務地・本人Overlayや通知は一切更新しません。</p></section>';
            $html.='<form method="post" action="'.self::PATH.'">'.Session::csrfField()
                .'<input type="hidden" name="stage" value="return"><input type="hidden" name="nonce" value="'.$token.'">'
                .'<label>差戻し理由<select name="reason" required>'
                .'<option value="missing_evidence">一次根拠が不足</option>'
                .'<option value="identity_unverified">法人・ブランドの同一性が未確認</option>'
                .'<option value="worksite_unverified">実勤務地が未確認</option>'
                .'<option value="vacancy_unverified">採用残枠が未確認</option>'
                .'<option value="overlay_conflict">既存情報と本人Overlayの競合が未解決</option>'
                .'</select></label><button class="btn ghost" type="submit">根拠を差戻す（監査のみ）</button></form>';
            $html.='<form method="post" action="'.self::PATH.'">'.Session::csrfField()
                .'<input type="hidden" name="stage" value="return_status"><input type="hidden" name="nonce" value="'.$token.'">'
                .'<button class="btn ghost" type="submit">差戻し状態を監査履歴から照会</button></form>';
        }
        $html.='<section class="panel"><h2>受入済み研究データの技術的取込プレビュー</h2>'
            .'<p>情報収集担当の確定データをそのまま利用します。<strong>1つのサイト用確定フィードJSON</strong>だけで27件の非公開ID計画を表示します。'
            .'任意の対応候補JSONは研究ID→既存seedキーの辞書形式です。候補名からの自動紐付けは行いません。</p></section>';
        $html.='<form class="setup-form" method="post" enctype="multipart/form-data" action="'.self::PATH.'">'.Session::csrfField()
            .'<input type="hidden" name="stage" value="technical_mapping_plan">'
            .'<label>確定研究フィードJSON<input type="file" name="accepted_feed" accept=".json,application/json" required></label>'
            .'<label>任意：技術的seed対応候補JSON<input type="file" name="technical_bindings" accept=".json,application/json"></label>'
            .'<button class="btn ghost" type="submit">非公開のID対応をプレビュー（DB変更なし）</button></form>';
        $html.='<section class="panel"><h2>確定研究フィードの非公開・原子的な保存（正式公開不可）</h2>'
            .'<p>研究担当の確定JSONを27件すべて、監査と送信禁止のOutbox保留記録とともに1回のDBトランザクションで保存します。'
            .'既存企業・ブランド・求人・勤務地のデータは更新しません。実DBへのID紐付けは任意で、明示されたIDと所有関係を確認できた場合だけ記録します。'
            .'同じ操作キーの再送は冪等、内容を変えた再利用は拒否します。取込後の正式公開・メール配送はできません。</p></section>';
        $html.='<form class="setup-form" method="post" enctype="multipart/form-data" action="'.self::PATH.'">'.Session::csrfField()
            .'<input type="hidden" name="stage" value="private_atomic_import">'
            .'<label>確定研究フィードJSON<input type="file" name="accepted_feed" accept=".json,application/json" required></label>'
            .'<label>任意：研究ID→DB ID/改訂番号の明示的な対応JSON<input type="file" name="verified_id_bindings" accept=".json,application/json"></label>'
            .'<label>再送時にも変えない操作キー（英数字・_・-・:、8〜100文字）'
            .'<input type="text" name="event_key" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9:_\-]{7,99}" required></label>'
            .'<button class="btn ghost" type="submit">非公開記録を原子的に保存（公開・通知しない）</button></form>';
        $html.='<section class="panel"><p>情報収集側の確定済み原典マニフェスト、企業差分JSON、調査報告Markdown、運営者の提出JSONを別々にアップロードしてください。seedカタログはサーバーに保持する固定版を使用します。</p><p><strong>読取り専用の事前検査</strong>（監査ログのみ保存）。原典のSHA256・版・27件、IDの重複・除外/HOLDを確認します。審査承認・公開・通知はできません。</p></section>';
        $html.='<form class="setup-form" method="post" enctype="multipart/form-data" action="'.self::PATH.'">'.Session::csrfField()
            .'<input type="hidden" name="stage" value="preflight">'
            .'<label>情報収集マニフェスト（JSON）<input type="file" name="manifest" accept=".json,application/json" required></label>'
            .'<label>企業差分（JSON）<input type="file" name="updates" accept=".json,application/json" required></label>'
            .'<label>企業調査レポート（Markdown）<input type="file" name="report" accept=".md,text/markdown,text/plain" required></label>'
            .'<label>根拠提出ファイル（JSON）<input type="file" name="evidence" accept=".json,application/json" required></label>'
            .'<button class="btn primary" type="submit">根拠を検査する（承認しない）</button></form>';
        $html.='<form class="setup-form" method="post" action="'.self::PATH.'">'.Session::csrfField()
            .'<button class="btn ghost" type="submit" name="stage" value="cancel">事前確認表示を取り消す</button></form>'
            .'<p><a href="/admin/data/external-links">企業・求人・応募リンク台帳 d1.0.2へ</a> ／ <a href="/admin/data">企業管理へ戻る</a> ／ <a href="/admin/data/reviewed-reimport">別の審査済み原典再取込へ</a></p>';
        Kernel::layout('企業同定の承認前確認',$html);
    }
    private static function error(int $code,string $message):void{
        http_response_code($code);Kernel::layout('企業調査の確認','<section class="hero compact"><h1>確認できません</h1><p>'.Security::h($message).'</p></section>',false);
    }
}
