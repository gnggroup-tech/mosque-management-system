<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\AccountStatusTransitionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdministrativeAccountStatusService
{
    public function __construct(
        private readonly AccountStatusTransitionService $transitionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function suspend(User $account, User $actor, string $reason): User
    {
        return $this->transition(
            $account,
            $actor,
            [AccountStatus::Active],
            AccountStatus::Suspended,
            $reason,
            'user.account.suspended',
        );
    }

    public function reactivate(User $account, User $actor, string $reason): User
    {
        return $this->transition(
            $account,
            $actor,
            [AccountStatus::Suspended],
            AccountStatus::Active,
            $reason,
            'user.account.reactivated',
        );
    }

    public function archive(User $account, User $actor, string $reason): User
    {
        return $this->transition(
            $account,
            $actor,
            [AccountStatus::Active, AccountStatus::Suspended],
            AccountStatus::Archived,
            $reason,
            'user.account.archived',
        );
    }

    /** @param list<AccountStatus> $allowedSources */
    private function transition(
        User $account,
        User $actor,
        array $allowedSources,
        AccountStatus $target,
        string $reason,
        string $event,
    ): User {
        return DB::transaction(function () use ($account, $actor, $allowedSources, $target, $reason, $event): User {
            $lockedAccount = User::query()->lockForUpdate()->find($account->getKey());
            if ($lockedAccount === null) {
                throw AccountStatusTransitionException::unpersistedAccount();
            }

            $from = $lockedAccount->status;
            if (! in_array($from, $allowedSources, true)) {
                throw AccountStatusTransitionException::invalidTransition($from->value, $target->value);
            }

            $updated = $this->transitionService->transition(
                $lockedAccount,
                $target,
                $actor,
                $target === AccountStatus::Suspended ? $reason : null,
            );

            $this->auditLogger->log(
                $event,
                $updated,
                [
                    'target_user_id' => $updated->getKey(),
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'occurred_at' => now()->toIso8601String(),
                    'reason' => $reason,
                ],
                $actor->getKey(),
            );

            return $updated;
        });
    }
}
