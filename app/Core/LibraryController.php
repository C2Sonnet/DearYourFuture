<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class LibraryController {
    public static function isRoute(string $path):bool {
        return $path==='/library'
            || preg_match('~\A/library/[a-f0-9]{32}(?:/download)?\z~D',$path)===1
            || preg_match('~\A/companies/[a-f0-9]{32}/library\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){header('Allow: GET');http_response_code(405);return;}
        $db=Database::connected();
        if($path==='/library'){self::listing($db,null);return;}
        if(preg_match('~\A/companies/([a-f0-9]{32})/library\z~D',$path,$m)){
            if(BusinessRepository::read($db,'companies',$m[1],true)===null){self::error(404);return;}
            self::listing($db,$m[1]);return;
        }
        if(preg_match('~\A/library/([a-f0-9]{32})(/download)?\z~D',$path,$m)){
            $doc=LibraryRepository::read($db,$m[1],true);
            if($doc===null){self::error(404);return;}
            if(isset($m[2])){self::send($db,$doc);return;}
            self::detail($db,$doc);return;
        }
        self::error(404);
    }
    private static function listing(PDO $db,?string $company):void {
        $raw=$_GET['page']??'1';$page=is_string($raw)&&preg_match('/\A[1-9]\d{0,2}\z/D',$raw)?min((int)$raw,500):1;
        $filters=LibraryRepository::normalizeSearch($_GET);
        $rows=LibraryRepository::search($db,$company,$filters,25,($page-1)*25);
        $title=$company===null?'企業資料ライブラリ':'企業別資料ライブラリ';
        $html='<section class="hero compact"><span class="eyebrow">DOCUMENT LIBRARY</span><h1>'.Security::h($title).'</h1><p>出典・公開許可を確認した企業資料だけを表示します。個人的な応募資料は公開しません。</p></section>';
        $base=$company===null?'/library':'/companies/'.$company.'/library';
        $html.='<form method="get" action="'.Security::h($base).'" class="directory-filters" role="search"><label>資料名<input name="q" maxlength="100" value="'.Security::h($filters['q']??'').'"></label><label>資料種別<select name="document_type"><option value="">すべて</option>';
        foreach(LibraryData::TYPES as $t)$html.='<option value="'.Security::h($t).'"'.(($filters['document_type']??'')===$t?' selected':'').'>'.Security::h(LibraryData::typeLabel($t)).'</option>';
        $html.='</select></label><label>根拠の種類<select name="source_type"><option value="">すべて</option>';
        foreach(BusinessData::SOURCE_TYPES as $t)$html.='<option value="'.Security::h($t).'"'.(($filters['source_type']??'')===$t?' selected':'').'>'.Security::h(BusinessData::sourceTypeLabel($t)).'</option>';
        $html.='</select></label><label>資料言語コード（ja＝日本語・en＝英語・ko＝韓国語）<input name="language_tag" maxlength="16" placeholder="ja / en / ko" value="'.Security::h($filters['language_tag']??'').'"></label><label>発行者<input name="issuer" maxlength="100" value="'.Security::h($filters['issuer']??'').'"></label><label>信頼度<select name="reliability"><option value="">すべて</option>';
        foreach(LibraryMetadata::RELIABILITY as $v)$html.='<option value="'.$v.'"'.(($filters['reliability']??'')===$v?' selected':'').'>'.Security::h(LibraryMetadata::reliabilityLabel($v)).'</option>';
        $html.='</select></label><label>資料の情報確認日（この日以降）<input type="date" name="observed_after" value="'.Security::h($filters['observed_after']??'').'"></label><button type="submit" class="btn primary">検索</button></form>';
        if(!$rows)$html.='<p class="note">条件に合う公開資料はありません。</p>';
        $html.='<div class="compact-card-grid library-card-grid">';
        foreach($rows as $r)$html.='<article class="panel library-row"><span class="step">'.Security::h(LibraryData::typeLabel($r['document_type'])).'・改訂 '.$r['current_version'].'</span><h2><a href="/library/'.Security::h($r['id']).'">'.Security::h($r['title']).'</a></h2><p>'.Security::h($r['company_name']).' ／ '.Security::h($r['description']??'').'</p><p>言語：'.Security::h(LibraryMetadata::languageLabel($r['language_tag']??null)).' ／ 発行者：'.Security::h($r['issuer']??'未確認').' ／ 信頼度：'.Security::h(LibraryMetadata::reliabilityLabel($r['reliability']??null)).'</p></article>';
        $html.='</div>';
        if($page>1)$html.='<a class="text-link" href="'.Security::h($base.'?'.http_build_query([...$filters,'page'=>$page-1])).'">前へ</a> ';
        if(count($rows)===25)$html.='<a class="text-link" href="'.Security::h($base.'?'.http_build_query([...$filters,'page'=>$page+1])).'">次へ</a>';
        $html.=ResearchR07::sourceLibrary($db,$company);Kernel::layout($title,$html);
    }
    private static function detail(PDO $db,array $r):void {
        $versions=LibraryRepository::versions($db,$r['id']);
        $html='<section class="hero compact"><span class="eyebrow">PROVENANCE · DOCUMENT</span><h1>'.Security::h($r['title']).'</h1><p>'.Security::h($r['description']??'').'</p></section>';
        $html.='<section class="section"><p>言語：'.Security::h(LibraryMetadata::languageLabel($r['language_tag']??null)).' ／ 発行者：'.Security::h($r['issuer']??'未確認').' ／ 信頼区分：'.Security::h(LibraryMetadata::reliabilityLabel($r['reliability']??null)).'</p><p>再配布許可確認日：'.Security::h($r['permission_checked_on']??'未確認').'</p><p>企業：<a href="/companies/'.Security::h($r['company_id']).'">'.Security::h($r['company_name']).'</a></p><p>種別：'.Security::h(LibraryData::typeLabel($r['document_type'])).' ／ 根拠：'.Security::h($r['source_title']??'未登録').'（分類：'.Security::h(BusinessData::sourceTypeLabel($r['source_type']??null)).'）</p><a href="/library/'.Security::h($r['id']).'/download" class="btn primary">最新ファイルを安全にダウンロード</a></section>';
        $html.='<section class="section"><h2>ファイルのバージョン履歴</h2><p>旧版は変更せず保持します。旧版ファイルのダウンロードは管理者のみ可能です。</p><ul>';
        foreach($versions as $v)$html.='<li>v'.(int)$v['version_no'].' / '.Security::h($v['created_at']).' / '.(int)$v['byte_size'].' bytes / SHA256 '.Security::h(substr($v['sha256'],0,16)).'…</li>';
        $html.='</ul><p class="note">SNS・口コミ・コミュニティの記述は採用条件や勤務地の確定根拠ではありません。公式情報と区別してご覧ください。</p></section>';Kernel::layout((string)$r['title'],$html);
    }
    /** Checks both the public document and exact AV-clean file version. */
    private static function canContinueDownload(PDO $db,array $doc,array $file):bool {
        $fresh=LibraryRepository::read($db,(string)$doc['id'],true);
        if($fresh===null||(int)$fresh['current_version']!==(int)$doc['current_version'])return false;
        $current=LibraryRepository::version($db,(string)$doc['id'],(int)$doc['current_version']);
        return is_array($current) && ($current['scan_status']??null)==='clean'
            && $current['storage_key']===$file['storage_key']
            && (int)$current['byte_size']===(int)$file['byte_size']
            && hash_equals((string)$file['sha256'],(string)$current['sha256']);
    }
    private static function send(PDO $db,array $doc):void {
        $file=LibraryRepository::version($db,$doc['id'],(int)$doc['current_version']);
        if(!$file||($file['scan_status']??null)!=='clean'){self::error(404);return;}
        $root=realpath(LibraryRepository::storageRoot());$key=(string)$file['storage_key'];
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)||$root===false){self::error(503);return;}
        $filepath=$root.'/'.$key;
        if(!is_file($filepath)||is_link($filepath)||realpath($filepath)!==$filepath){self::error(503);return;}
        // Open once: bind all integrity checks and the download to the same inode.
        $handle=@fopen($filepath,'rb');
        if($handle===false){self::error(503);return;}
        try{
            $handleStat=fstat($handle);$pathStat=@lstat($filepath);
            if(!is_array($handleStat)||!is_array($pathStat)
                ||($handleStat['mode']&0170000)!==0100000
                ||($pathStat['mode']&0170000)!==0100000
                ||$handleStat['ino']!==$pathStat['ino']||$handleStat['dev']!==$pathStat['dev']
                ||(int)$handleStat['size']!==(int)$file['byte_size']){self::error(503);return;}
            $hasher=hash_init('sha256');
            if(hash_update_stream($hasher,$handle)!==(int)$file['byte_size']
                ||!hash_equals((string)$file['sha256'],hash_final($hasher))
                ||!rewind($handle)){self::error(503);return;}
            // The detail page or the first hash pass may have preceded a revoke.
            $fresh=LibraryRepository::read($db,(string)$doc['id'],true);
            if($fresh===null||(int)$fresh['current_version']!==(int)$doc['current_version']
                ||!self::canContinueDownload($db,$doc,$file)){self::error(404);return;}
            $name=(string)$file['original_name'];$ascii='company-document';
            header('Content-Type: '.(string)$file['media_type']);
            header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name));
            header('Content-Length: '.(string)$file['byte_size']);
            header('Content-Security-Policy: sandbox');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            header('X-Accel-Buffering: no');
            try{
                LibraryStream::transfer($handle,(int)$file['byte_size'],
                    static fn()=>self::canContinueDownload($db,$doc,$file),
                    static function(string $bytes):void{echo $bytes;});
            }catch(\Throwable $e){
                // Headers may already be sent. Truncate, log and abort rather than
                // continue sending a newly private document.
                error_log('KMM protected download interrupted: '.get_class($e));
            }
        }finally{fclose($handle);}
    }
    private static function error(int $status):void {http_response_code($status);Kernel::layout('資料を表示できません','<section class="hero compact"><h1>資料を表示できません</h1><p>資料が非公開、または現在利用できない状態です。</p></section>');}
}
