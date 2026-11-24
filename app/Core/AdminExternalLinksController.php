<?php
declare(strict_types=1);
namespace KMM\Core;
/** ADMIN-facing research link catalog import with explicit, non-name-derived DB mapping. */
final class AdminExternalLinksController {
    public const PATH='/admin/data/external-links';
    private static function upload(mixed $f,int $max):string {
        if(!is_array($f)||($f['error']??null)!==UPLOAD_ERR_OK||!is_int($f['size']??null)||$f['size']<2||$f['size']>$max
            ||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name']))throw new \DomainException('Invalid upload');
        $raw=file_get_contents($f['tmp_name']);
        if(!is_string($raw)||strlen($raw)!==$f['size'])throw new \DomainException('Incomplete upload');
        return $raw;
    }
    public static function handle(string $method,array $actor):void {
        if(!in_array($actor['role']??null,['ADMIN','FOUNDER','DEVELOPER'],true)||!is_int($actor['id']??null)||$actor['id']<1){self::error(403);return;}
        if($method!=='GET'&&$method!=='POST'){header('Allow: GET, POST');self::error(405);return;}
        $notice='';
        if($method==='POST'){
            $authenticated=(int)($_SESSION['authenticated_at']??0);$now=time();
            if(!is_string($_POST['csrf']??null)||!Session::validCsrf($_POST['csrf'])||$authenticated<=0||$authenticated>$now||$now-$authenticated>900){self::error(403);return;}
            if(!Throttle::allow('external-links-import:'.$actor['id'],4,900)){self::error(429);return;}
            try {
                $manifest=self::upload($_FILES['manifest']??null,8192);
                $catalog=self::upload($_FILES['catalog']??null,100000);
                $f=$_FILES['mapping']??null;
                $mapping=$f===null||($f['error']??null)===UPLOAD_ERR_NO_FILE ? ['companies'=>[],'jobs'=>[]] :
                    json_decode(self::upload($f,65536),true,128,JSON_THROW_ON_ERROR);
                if(!is_array($mapping))throw new \DomainException('Invalid mapping JSON');
                $publishing=false;foreach(($mapping['companies']??[]) as $entry)if(is_array($entry)&&($entry['publish']??false)===true)$publishing=true;
                if($publishing&&($_POST['confirm_public_links']??null)!=='yes')throw new \DomainException('Public link publication requires explicit confirmation');
                $plan=ResearchExternalLinks::parse($manifest,$catalog);
                $result=ResearchExternalLinkRepository::ingest(Database::connected(),$plan,$mapping,$actor,(int)($_SESSION['auth_version']??-1));
                $notice='<p role="status">確定リンク台帳：'.(int)$result['links_total'].'件。追加'.(int)$result['inserted'].'件、技術的紐付け'.(int)$result['mapped'].'件、公開対応'.(int)$result['published'].'件。実際の応募は実施していません。</p>';
            }catch(\DomainException|\InvalidArgumentException|\JsonException){self::error(422);return;}
            catch(\Throwable $e){error_log('External link import failure: '.get_class($e));self::error(409);return;}
        }
        $html='<section class="hero compact"><h1>外部リンク台帳 d1.0.2</h1><p>研究確定データ59社・141件の公式・採用・求人・応募リンクを受け入れます。リンクは初期状態で非公開です。</p></section>'
            .$notice.'<section class="section"><h2>台帳のインポート・技術的紐付け</h2><p>研究の原典を再調査しません。会社名だけの自動紐付けは禁止し、DB上のIDとrevisionを明示して初めてリンクを公開します。除外・保留は公開できません。</p>'
            .'<form method="post" enctype="multipart/form-data" action="'.self::PATH.'" class="setup-form">'.Session::csrfField()
            .'<label>研究マニフェスト d1.0.2<input type="file" name="manifest" accept=".json" required></label>'
            .'<label>リンク構造化台帳 d1.0.2<input type="file" name="catalog" accept=".json" required></label>'
            .'<label>任意：明示的なサイトDB ID対応JSON<input type="file" name="mapping" accept=".json"></label>'
            .'<label><input type="checkbox" name="confirm_public_links" value="yes"> 明示したIDに紐付けたpublish=trueのURLは、既に公開されている会社・求人ページに表示されます。公開対象を確認しました。</label>'
            .'<button class="btn primary" type="submit">台帳を受入・明示したリンクの公開指定を反映</button></form>'
            .'<p class="note">対応JSONの形式：{"companies":{"研究上の会社名":{"company_id":"32桁のDB ID","expected_revision":1,"publish":false}},"jobs":{"リンクキー":"32桁の求人DB ID"}}。公開はpublish=trueで明示し、公開企業のみ対象になります。</p>'
            .'<p><a href="/admin/data/research-identities">企業データ取込・内部審査へ</a></p></section>';
        Kernel::layout('外部リンク台帳', $html,false);
    }
    private static function error(int $code):void {
        http_response_code($code);
        Kernel::layout('リンク台帳の取込を中止しました','<section class="hero compact"><h1>リンク台帳を登録できません</h1><p>権限、入力ファイル、既存DB ID、リンク重複または改訂番号を確認してください。研究の内容は変更しません。</p><a href="'.self::PATH.'">戻る</a></section>',false);
    }
}
