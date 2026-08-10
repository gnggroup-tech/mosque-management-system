<?php

namespace App\Enums;

enum AccountStatus: string
{
    case PendingEmail = 'pending_email';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PendingEmail => $target === self::PendingApproval,
            self::PendingApproval => $target === self::Active,
            self::Active => in_array($target, [self::Suspended, self::Archived], true),
            self::Suspended => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }
}
