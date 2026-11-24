<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Admin-only upload, versioning and publication workflow. */
final class LibraryAdminController {
    public static function isRoute(string $path):bool {
        return $path==='/admin/library'||preg_match('~\A/admin/library/[a-f0-9]{32}\z~D',$path)===1;
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array($user['role']??null,['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);return;}
        $db=Database::connected();$id=$path==='/admin/library'?null:substr($path,strlen('/admin/library/'));
        $doc=$id===null?null:LibraryRepository::read($db,$id,false);
        if($id!==null && $doc===null){self::message('資料が見つかりません',404);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::message('CSRF検証に失敗しました',403);return;}
            $newFile=null;
            try {
                if(($_POST['action']??'')==='metadata'){
                    if($id===null)throw new \InvalidArgumentException('Save the document privately first');
                    LibraryMetadata::set($db,$id,(int)$user['id'],$_POST);
                    header('Location: /admin/library/'.$id.'?metadata=saved',true,303);return;
                }
                $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
                if($id!==null && ($revision===false||$revision===null||$revision<1))throw new \InvalidArgumentException('Valid revision required');
                if(($action=$_POST['action']??'save')==='archive'){
                    if($id===null||($_POST['confirmed']??'')!=='yes')throw new \InvalidArgumentException('Archive requires confirmation');
                    LibraryRepository::archive($db,$id,$revision,(int)$user['id']);header('Location: /admin/library?archived=1',true,303);return;
                }
                if($action!=='save')throw new \InvalidArgumentException('Invalid action');
                $company=is_string($_POST['company_id']??null)?$_POST['company_id']:'';
                if(isset($_FILES['document'])&&is_array($_FILES['document'])&&($_FILES['document']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$newFile=LibraryRepository::storeUploadedFile($_FILES['document']);}
                if($id===null){
                    if($newFile===null)throw new \InvalidArgumentException('New documents require a file');
                    $created=LibraryRepository::create($db,$company,$_POST,$newFile,(int)$user['id']);
                    header('Location: /admin/library/'.$created.'?created=1',true,303);return;
                }
                LibraryRepository::update($db,$id,$revision,$_POST,$newFile,(int)$user['id']);
                header('Location: /admin/library/'.$id.'?saved=1',true,303);return;
            }catch(\Throwable $e){
                if($newFile!==null)LibraryRepository::discard((string)$newFile['storage_key']);
                if($e instanceof \InvalidArgumentException){self::message($e->getMessage(),422);return;}
                if($e instanceof \RuntimeException){self::message('更新競合または保存処理に失敗しました',409);return;}
                error_log('KMM document save error '.get_class($e));self::message('資料を保存できませんでした',503);return;
            }
        }
        self::page($db,$doc);
    }
    private static function page(PDO $db,?array $current):void {
        $html='<section class="hero compact"><span class="eyebrow">ADMIN · DOCUMENT LIBRARY</span><h1>企業資料ライブラリ管理</h1><p>公開資料は出典と企業公開状態の条件を満たす必要があります。ファイル本体は非公開領域に保存します。</p></section>';
        if($current===null){
            $records=LibraryRepository::list($db,false,null,50,0);
            $html.='<div class="section"><h2>登録済み資料</h2>';
            foreach($records as $r)$html.='<p><a class="text-link" href="/admin/library/'.$r['id'].'">'.Security::h($r['title']).'</a>（'.Security::h(($r['publication_state']??'private')==='public'?'公開':'管理者専用').'）</p>';
            if(!$records)$html.='<p>資料はまだ登録されていません。</p>';
            $html.='</div><h2>新しい資料を登録</h2>';
        }else {
            $html.='<p class="note">現在の改訂 '.(int)$current['revision'].' / ファイル v'.(int)$current['current_version'].'</p>';
        }
        $action='/admin/library'.($current===null?'':'/'.$current['id']);
        $html.='<form enctype="multipart/form-data" method="post" action="'.$action.'" class="setup-form">'.Session::csrfField().'<input type="hidden" name="action" value="save">';
        if($current!==null)$html.='<input type="hidden" name="revision" value="'.(int)$current['revision'].'">';
        $fields=[
            ['company_id','企業ID（32桁、作成後変更不可）',$current['company_id']??''],
            ['title','タイトル',$current['title']??''],
            ['description','説明',$current['description']??''],
            ['source_id','公開可能な根拠資料ID',$current['source_id']??''],
        ];
        foreach($fields as [$key,$label,$val]){
            $disabled=$key==='company_id'&&$current!==null?' readonly':'';
            $html.='<label>'.Security::h($label).'<input type="text" name="'.Security::h($key).'" value="'.Security::h((string)$val).'"'.$disabled.'></label>';
        }
        $html.='<label>文書の種類<select name="document_type">';
        foreach(LibraryData::TYPES as $kind)$html.='<option value="'.$kind.'"'.(($current['document_type']??'other')===$kind?' selected':'').'>'.Security::h(LibraryData::typeLabel($kind)).'</option>';
        $html.='</select></label><label>公開状態<select name="publication_state"><option value="private"'.(($current['publication_state']??'private')==='private'?' selected':'').'>管理者のみ</option><option value="public"'.(($current['publication_state']??'')==='public'?' selected':'').'>公開</option></select></label>';
        $html.='<p class="note">ウイルス対策ソフトによる検査が完了していない版は公開できません。検査環境未導入時は非公開のまま保存してください。</p><label>PDF・PNG・JPG・TXT（上限8 MB）<input type="file" name="document"'.($current===null?' required':'').' accept=".pdf,.png,.jpg,.jpeg,.txt"></label><p class="note">ファイル更新は古い版を残して新しい版を追加します。</p><button class="btn primary" type="submit">保存する</button></form>';
        if($current!==null){
            $metadata=LibraryMetadata::get($db,$current['id']);
            $html.='<section class="section"><h2>出典・再配布許可の確認</h2><p class="note">出典が公開されていても、ファイルの再配布許可は別です。許可の確認日と根拠を記録するまで資料は一般公開されません。</p><form method="post" class="setup-form" action="'.$action.'">'.Session::csrfField().'<input type="hidden" name="action" value="metadata"><input type="hidden" name="meta_revision" value="'.(int)($metadata['revision']??0).'">';
            $html.='<label>言語コード（ja＝日本語・en＝英語・ko＝韓国語・und＝未確認）<input name="language_tag" maxlength="16" value="'.Security::h($metadata['language_tag']??'und').'"></label><label>発行者<input name="issuer" maxlength="240" value="'.Security::h($metadata['issuer']??'').'"></label><label>資料の信頼区分<select name="reliability">';
            foreach(LibraryMetadata::RELIABILITY as $x)$html.='<option value="'.$x.'"'.(($metadata['reliability']??'unknown')===$x?' selected':'').'>'.Security::h(LibraryMetadata::reliabilityLabel($x)).'</option>';
            $html.='</select></label><label>再配布許可<select name="distribution_permission">';
            foreach(LibraryMetadata::PERMISSIONS as $x)$html.='<option value="'.$x.'"'.(($metadata['distribution_permission']??'unknown')===$x?' selected':'').'>'.Security::h(LibraryMetadata::permissionLabel($x)).'</option>';
            $html.='</select></label><label>許可確認日<input type="date" name="permission_checked_on" value="'.Security::h($metadata['permission_checked_on']??'').'"></label><label>許諾の根拠・確認方法<textarea name="permission_note" maxlength="1500">'.Security::h($metadata['permission_note']??'').'</textarea></label><button class="btn primary" type="submit">許可記録を保存</button></form></section>';
            $versions=LibraryRepository::versions($db,$current['id']);
            $html.='<section class="section"><h2>保存済みバージョン</h2><ul>';
            foreach($versions as $v)$html.='<li>v'.(int)$v['version_no'].' ／ SHA256 '.Security::h($v['sha256']).'</li>';
            $html.='</ul><form method="post" action="'.$action.'" class="setup-form">'.Session::csrfField().'<input type="hidden" name="revision" value="'.(int)$current['revision'].'"><input type="hidden" name="action" value="archive"><label><input type="checkbox" name="confirmed" value="yes" required> この資料をアーカイブする</label><button type="submit" class="btn ghost">非公開にして保存</button></form></section>';
        }
        Kernel::layout('企業資料管理',$html,false);
    }
    private static function message(string $message,int $status):void {
        http_response_code($status);Kernel::layout('資料管理','<section class="hero compact"><h1>操作を完了できませんでした</h1><p>'.Security::h($message).'</p></section>',false);
    }
}
