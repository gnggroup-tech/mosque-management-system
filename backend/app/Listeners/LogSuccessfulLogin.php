<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function handle(Login $event): void
    {
        $this->auditLogger->log('auth.login', $event->user, [], $event->user->getAuthIdentifier());
    }
}
