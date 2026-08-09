<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Logout;

class LogSuccessfulLogout
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function handle(Logout $event): void
    {
        if ($event->user !== null) {
            $this->auditLogger->log('auth.logout', $event->user, [], $event->user->getAuthIdentifier());
        }
    }
}
