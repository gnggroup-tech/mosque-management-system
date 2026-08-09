<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function created(User $user): void
    {
        $this->auditLogger->log('user.created', $user, [
            'email' => $user->email,
        ]);
    }

    public function updated(User $user): void
    {
        $changes = collect($user->getChanges())
            ->except(['password', 'remember_token', 'updated_at'])
            ->all();

        if ($changes !== []) {
            $this->auditLogger->log('user.updated', $user, [
                'changes' => $changes,
            ]);
        }
    }

    public function deleted(User $user): void
    {
        $this->auditLogger->log('user.deleted', null, [
            'user_id' => $user->getKey(),
            'email' => $user->email,
        ]);
    }
}
