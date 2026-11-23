<?php
declare(strict_types=1);
namespace KMM\Core;
/** Synthetic sender identifier: never allocate a normal login identity. */
final class ReservedAccounts {
    public const MINA = 'MinaHelper';
    public static function reserved(string $handle): bool {
        return strcasecmp($handle,self::MINA)===0;
    }
    public static function display(?string $handle):string {
        return $handle!==null&&self::reserved($handle)?'ミナヘルパー (@MinaHelper)':'';
    }
}
