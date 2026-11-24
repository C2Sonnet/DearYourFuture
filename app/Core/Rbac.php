<?php
declare(strict_types=1);
namespace KMM\Core;
final class Rbac {
    public const RANK = ['USER'=>1,'ADMIN'=>2,'FOUNDER'=>3,'DEVELOPER'=>4];
    public static function canManageAccount(string $actorRole, string $targetRole, bool $self=false): bool {
        if ($self) { return isset(self::RANK[$actorRole]); } // profile only, not role elevation
        return match ($actorRole) {
            'DEVELOPER' => in_array($targetRole, ['USER','ADMIN','FOUNDER'], true),
            'FOUNDER' => in_array($targetRole, ['USER','ADMIN'], true),
            'ADMIN' => $targetRole === 'USER',
            default => false,
        };
    }
    public static function canCreateAccount(string $actorRole, string $role): bool {
        return match ($actorRole) {
            'DEVELOPER' => in_array($role,['USER','ADMIN','FOUNDER'],true),
            'FOUNDER' => in_array($role,['USER','ADMIN'],true),
            'ADMIN' => $role === 'USER',
            default => false,
        };
    }
    public static function canTechnicalSettings(string $role): bool {
        return $role === 'DEVELOPER';
    }
}
