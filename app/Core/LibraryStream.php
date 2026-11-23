<?php
declare(strict_types=1);
namespace KMM\Core;
/** Bounded delivery with a fresh permission gate before each chunk.
 * A revoked file stops the *remaining* output; bytes already received cannot
 * be recalled. Real concurrent revocation still needs external E2E validation.
 */
final class LibraryStream {
    public const CHUNK_SIZE=262144;
    /** @param resource $stream */
    public static function transfer($stream,int $expectedBytes,callable $allowed,callable $write,int $chunk=self::CHUNK_SIZE):int {
        if(!is_resource($stream)||$expectedBytes<0||$chunk<1||$chunk>1048576)throw new \InvalidArgumentException('Invalid streaming parameters');
        $sent=0;
        while($sent<$expectedBytes){
            if(!$allowed())throw new \RuntimeException('Permission withdrawn during download');
            $bytes=fread($stream,min($chunk,$expectedBytes-$sent));
            if(!is_string($bytes)||$bytes==='')throw new \RuntimeException('Source stream shortened');
            $write($bytes);
            $sent+=strlen($bytes);
        }
        return $sent;
    }
}
