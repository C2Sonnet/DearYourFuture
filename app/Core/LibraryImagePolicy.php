<?php
declare(strict_types=1);
namespace KMM\Core;

/** Reject malformed/oversized image dimensions before library file storage. */
final class LibraryImagePolicy {
    public static function validate(string $mime, array|false $dim): void {
        if ($mime !== 'image/jpeg' && $mime !== 'image/png') return; // Other permitted library documents remain unchanged.
        $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if (!is_array($dim) || ($dim[2] ?? null) !== $expected
            || !is_int($dim[0] ?? null) || !is_int($dim[1] ?? null)
            || $dim[0] < 1 || $dim[1] < 1 || $dim[0] > 8192 || $dim[1] > 8192
            || $dim[0] * $dim[1] > 25000000) {
            throw new \InvalidArgumentException('Invalid or oversized image dimensions');
        }
    }
}
