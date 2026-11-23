<?php
declare(strict_types=1);
namespace KMM\Core;
/**
 * Two-request manual preview/commit; upload bytes are never cached in a session.
 * Actual site DB authorization is checked again under transaction locks.
 */
final class AdminResearchPublicationController {
    public const PATH='/admin/data/research-publication';
    private const UPLOADS=[
        'research_manifest'=>32768,
        'research_updates'=>250000,
        'research_report'=>250000,
        'link_manifest'=>32768,
        'link_catalog'=>200000,
        'decisions'=>150000,
    ];
    private static function upload(string $name,int $limit):string {
        $f=$_FILES[$name]??null;
        if(!is_array($f)||($f['error']??null)!==UPLOAD_ERR_OK
          ||!is_int($f['size']??null)||$f['size']<2||$f['size']>$limit
          ||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name']))
            throw new \DomainException('Missing, oversized or invalid upload');
        $data=file_get_contents($f['tmp_name']);
        if(!is_string($data)||strlen($data)!==$f['size'])throw new \DomainException('Invalid uploaded bytes');
        return $data;
    }
    public static function handle(string $method,array $actor):void {
        if(!is_int($actor['id']??null)||$actor['id']<1
          ||!in_array($actor['role']??null,['ADMIN','FOUNDER','DEVELOPER'],true)){
            self::error(403);return;
        }
        if($method!=='GET'&&$method!=='POST'){
            header('Allow: GET, POST');self::error(405);return;
        }
        $notice='';
        if($method==='POST'){
            $now=time();$authTime=(int)($_SESSION['authenticated_at']??0);
            if(!is_string($_POST['csrf']??null)||!Session::validCsrf($_POST['csrf'])
               ||$authTime<=0||$authTime>$now||$now-$authTime>900){self::error(403);return;}
            if(!Throttle::allow('research-publication:'.$actor['id'],6,900)){self::error(429);return;}
            try{
                $input=[];foreach(self::UPLOADS as $field=>$max)$input[$field]=self::upload($field,$max);
                $feed=ResearchAcceptedFeed::make($input['research_manifest'],$input['research_updates'],$input['research_report']);
                $linkPlan=ResearchExternalLinks::parse($input['link_manifest'],$input['link_catalog']);
                $decisions=json_decode($input['decisions'],true,128,JSON_THROW_ON_ERROR);
                if(!is_array($decisions))throw new \DomainException('Invalid decisions document');
                $plan=ResearchPublicationAtomic::plan($feed,$linkPlan,$decisions);
                $action=$_POST['action']??null;
                if($action==='preview'){
                    $result=ResearchPublicationAtomic::preview(Database::connected(),$plan);
                    $notice='<section class="panel"><h2>読取り専用プレビュー</h2><p>指定対象：補充情報 '.(int)$result['claims'].'件、外部リンク '.(int)$result['links'].'件、実会社'.(int)$result['companies'].'件、求人'.(int)$result['jobs'].'件。個人編集内容は表示しません。</p>'
                        .'<p>プレビュー識別子（対応JSON・DB値に束縛）：<code>'.Security::h($result['digest']).'</code></p>'
                        .'<p>確定する場合は、対象IDと公開状態を確認し、同じ6ファイルを再提出してください。公開チェックと識別子が必要です。再プレビュー後のDB変更は拒否されます。</p></section>';
                }elseif($action==='commit'){
                    $preview=$_POST['preview_digest']??null;
                    $event=$_POST['event_key']??null;
                    if(!is_string($preview)||!is_string($event)||($_POST['confirm']??null)!=='yes')
                        throw new \DomainException('Explicit commit confirmation required');
                    $result=ResearchPublicationAtomic::apply(Database::connected(),$plan,$actor,
                        (int)($_SESSION['auth_version']??-1),$authTime,$now,$event,$preview,true);
                    $notice='<p role="status">技術的反映：'.Security::h($result['status']).'。補充'.(int)($result['claim_changes']??0).'件、リンク'.(int)($result['link_changes']??0).'件。自動通知の本配送は行いません。</p>';
                }else throw new \DomainException('Unknown operation');
            }catch(\DomainException|\InvalidArgumentException|\JsonException){self::error(422);return;}
            catch(\Throwable $e){error_log('Research publication error: '.get_class($e));self::error(409);return;}
        }
        $html='<section class="hero compact"><h1>確定研究の技術的なDB反映</h1><p>研究原典を再調査せず、会社・求人の実DB安定IDとrevisionで技術的な対応を行います。未確認情報を確定条件へ変えず、本人Overlayを上書きしません。</p></section>'
            .$notice.'<section class="section"><h2>プレビューと明示的な反映</h2><p>最初はプレビューを実行してください。確認後、同じファイルを再アップロードして識別子を指定します。実行環境にMySQL8.4などがなくても確認済みとは扱いません。</p>'
            .'<form method="post" enctype="multipart/form-data" class="setup-form" action="'.self::PATH.'">'.Session::csrfField()
            .'<label>研究d1.0.1 Manifest<input type="file" name="research_manifest" accept=".json" required></label>'
            .'<label>研究d1.0.1 Updates<input type="file" name="research_updates" accept=".json" required></label>'
            .'<label>研究d1.0.1 原典報告<input type="file" name="research_report" accept=".md,.txt" required></label>'
            .'<label>リンクd1.0.2 Manifest<input type="file" name="link_manifest" accept=".json" required></label>'
            .'<label>リンクd1.0.2 Catalogue<input type="file" name="link_catalog" accept=".json" required></label>'
            .'<label>明示的な決定JSON<input type="file" name="decisions" accept=".json" required></label>'
            .'<label>操作を選択<select name="action" required><option value="preview">プレビュー（DB書込みなし）</option><option value="commit">反映（確認済みの場合のみ）</option></select></label>'
            .'<label>プレビュー識別子（反映する場合に必須）<input name="preview_digest" maxlength="64" autocomplete="off" pattern="[a-f0-9]{64}"></label>'
            .'<label>操作固有イベントキー（反映する場合に必須）<input name="event_key" minlength="8" maxlength="100" autocomplete="off"></label>'
            .'<label><input type="checkbox" name="confirm" value="yes"> ID・公開可否・応募に関する未確認表示を確認し、明示した公開操作を実施します。</label>'
            .'<button class="btn primary" type="submit">指定した操作を実行</button></form>'
            .'<p class="note">決定JSON：{"claims":[{"id":"補充候補ID","company_id":"32桁","company_revision":1,"job_id":"32桁または省略","job_revision":1,"publish":false}],"links":[]}。job_idとjob_revisionは必ず対で指定。公開はpublish=trueを明示し、既存の公開会社に限る。求人URLは求人IDも必須。</p>'
            .'<p>通知Outboxはheld状態で保存され、ここからメールやサイト内一斉配信を開始しません。<a href="/admin/data/research-identities">研究データの非公開取込・管理へ</a></p></section>';
        Kernel::layout('研究データ技術反映', $html,false);
    }
    private static function error(int $code):void {
        http_response_code($code);
        Kernel::layout('研究データ反映中止','<section class="hero compact"><h1>反映できませんでした</h1><p>権限、原典版、ID/revision、プレビューと現DB状態を確認してください。安全のため研究原典と本人データは変更しません。</p><a href="'.self::PATH.'">戻る</a></section>',false);
    }
}
