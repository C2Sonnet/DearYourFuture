<?php
declare(strict_types=1);
namespace KMM\Core;

/** Pure upload and encoded-file checks. These do not replace GD or real HTTP testing. */
final class AvatarImagePolicy {
    public const MAX_BYTES = 2097152;
    public const EDGE = 512;
    private const FORMATS = ['image/jpeg' => ['jpg', IMAGETYPE_JPEG], 'image/png' => ['png', IMAGETYPE_PNG]];

    public static function validateUpload(array $file, string $mime, array|false $dimension, int|false $actualSize): string {
        $size = $file['size'] ?? null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($size) || $size < 1 || $size > self::MAX_BYTES || $actualSize === false || $actualSize !== $size) {
            throw new \InvalidArgumentException('画像サイズまたは送信状態が不正です');
        }
        if (!isset(self::FORMATS[$mime]) || !is_array($dimension)
            || ($dimension[0] ?? null) !== self::EDGE || ($dimension[1] ?? null) !== self::EDGE
            || ($dimension[2] ?? null) !== self::FORMATS[$mime][1]) {
            throw new \InvalidArgumentException('512×512pxのJPEGまたはPNGを選んでください');
        }
        return self::FORMATS[$mime][0];
    }

    /** Validate the *newly encoded* bytes, not only their extension. */
    public static function encodedFileValid(string $path, string $extension): bool {
        if (!is_file($path)) return false;
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) return false;
        $mime = @((new \finfo(FILEINFO_MIME_TYPE))->file($path));
        if (!is_string($mime) || !isset(self::FORMATS[$mime]) || self::FORMATS[$mime][0] !== $extension) return false;
        $dim = @getimagesize($path);
        return is_array($dim) && ($dim[0] ?? null) === self::EDGE && ($dim[1] ?? null) === self::EDGE
            && ($dim[2] ?? null) === self::FORMATS[$mime][1];
    }
}
