<?php
declare(strict_types=1);
namespace KMM\Core;
/** Admin avatar upload uses the same private storage rules as the member profile. */
final class AdminAvatarUpload {
    public static function hasImage(mixed $upload):bool{return is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;}
    public static function store(mixed $upload):string {
        if(!is_array($upload)||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name']))throw new \InvalidArgumentException('アップロードファイルを確認してください');
        $path=$upload['tmp_name'];
        $mime=@(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext=AvatarImagePolicy::validateUpload($upload,is_string($mime)?$mime:'',@getimagesize($path),@filesize($path));
        if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg')||!function_exists('imagepng'))throw new \RuntimeException('GD画像変換が利用できません');
        $bytes=@file_get_contents($path);$image=is_string($bytes)?@imagecreatefromstring($bytes):false;unset($bytes);
        if($image===false)throw new \InvalidArgumentException('画像をデコードできません');
        $canvas=imagecreatetruecolor(512,512);
        if($canvas===false){imagedestroy($image);throw new \RuntimeException('画像変換を開始できません');}
        if($ext==='png'){
            imagealphablending($canvas,false);imagesavealpha($canvas,true);
            imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));
        }else imagefill($canvas,0,0,imagecolorallocate($canvas,227,216,213));
        $ok=imagecopyresampled($canvas,$image,0,0,0,0,512,512,imagesx($image),imagesy($image));
        imagedestroy($image);
        if(!$ok){imagedestroy($canvas);throw new \RuntimeException('画像処理に失敗しました');}
        try{
            return AvatarFileStore::save(SiteState::root().'/storage/private/avatars',$ext,
                static fn(string $stage):bool=>$ext==='png'?@imagepng($canvas,$stage,6):@imagejpeg($canvas,$stage,85));
        }finally{imagedestroy($canvas);}
    }
    public static function markup(bool $optional):string {
        return '<label>アバター画像（JPEG/PNG、最大2MB、任意）<input type="file" name="avatar" accept="image/jpeg,image/png"'.($optional?'':' required').'></label>'
            .'<p class="note">アップロードは任意です。選択した画像を正方形に切り抜いて512×512pxで保存します。未指定なら初期アバターが生成されます。</p>'
            .'<div class="avatar-crop-ui" hidden><canvas width="512" height="512" tabindex="0" role="img" aria-label="アバターの切り抜きプレビュー"></canvas><div class="avatar-crop-controls"><label>形式<select name="avatar_format" data-avatar-format><option value="image/jpeg" selected>JPEG</option><option value="image/png">PNG</option></select></label><label>拡大<input type="range" data-avatar-zoom min="1" max="3" step="0.05" value="1"></label><button type="button" class="btn ghost" data-avatar-reset>リセット</button></div></div><p class="avatar-editor-error" role="alert"></p><p data-avatar-status role="status"></p>';
    }
}
