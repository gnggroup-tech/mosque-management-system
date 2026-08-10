<?php

namespace App\Enums;

enum AccountStatus: string
{
    case PendingEmail = 'pending_email';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
