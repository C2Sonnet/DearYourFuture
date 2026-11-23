<?php
declare(strict_types=1);
namespace KMM\Core;

/** Atomic file promotion. Caller commits the database before retiring the old avatar. */
final class AvatarFileStore {
    public static function save(string $dir, string $extension, callable $encode): string {
        if (!in_array($extension, ['jpg', 'png'], true)) throw new \InvalidArgumentException('Unsupported avatar format');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Private avatar directory unavailable');
        if (!is_writable($dir)) throw new \RuntimeException('Private avatar directory is not writable');
        $temp = @tempnam($dir, '.avatar-stage-');
        if (!is_string($temp)) throw new \RuntimeException('Cannot create avatar staging file');
        @chmod($temp, 0600);
        try {
            if ($encode($temp) !== true || !AvatarImagePolicy::encodedFileValid($temp, $extension)) {
                throw new \RuntimeException('Invalid or oversized encoded avatar');
            }
            $new = bin2hex(random_bytes(16)).'.'.$extension;
            if (!@rename($temp, $dir.'/'.$new)) throw new \RuntimeException('Cannot promote encoded avatar');
            @chmod($dir.'/'.$new, 0600);
            return $new;
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    public static function discard(string $dir, string $name): void {
        if (preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp)\z/D', $name) !== 1) return;
        $path = $dir.'/'.$name;
        // Never follow a symlink to an unrelated file.
        if (is_file($path) && !is_link($path)) @unlink($path);
    }
}
