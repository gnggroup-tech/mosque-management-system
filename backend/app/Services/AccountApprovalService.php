<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\AccountStatusTransitionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountApprovalService
{
    public function __construct(
        private readonly AccountStatusTransitionService $transitionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function approve(User $account, User $actor): User
    {
        return DB::transaction(function () use ($account, $actor): User {
            $lockedAccount = User::query()->lockForUpdate()->find($account->getKey());
            if ($lockedAccount === null) {
                throw AccountStatusTransitionException::unpersistedAccount();
            }

            if ($lockedAccount->status !== AccountStatus::PendingApproval) {
                throw AccountStatusTransitionException::invalidTransition(
                    $lockedAccount->status->value,
                    AccountStatus::Active->value,
                );
            }

            $approved = $this->transitionService->transition(
                $lockedAccount,
                AccountStatus::Active,
                $actor,
            );

            $this->auditLogger->log(
                'user.account.approved',
                $approved,
                [
                    'target_user_id' => $approved->getKey(),
                    'from_status' => AccountStatus::PendingApproval->value,
                    'to_status' => AccountStatus::Active->value,
                    'occurred_at' => $approved->activated_at->toIso8601String(),
                    'reason' => 'administrative_approval',
                ],
                $actor->getKey(),
            );

            return $approved;
        });
    }
}
