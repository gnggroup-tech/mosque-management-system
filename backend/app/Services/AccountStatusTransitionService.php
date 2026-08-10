<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\AccountStatusTransitionException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountStatusTransitionService
{
    private const MAX_SUSPENSION_REASON_BYTES = 65535;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function transition(
        User $account,
        AccountStatus $target,
        User $actor,
        ?string $reason = null,
    ): User {
        $this->ensurePersisted($account, $actor);
        $normalizedReason = $target === AccountStatus::Suspended
            ? $this->normalizeSuspensionReason($reason)
            : null;

        return DB::transaction(function () use ($account, $target, $actor, $normalizedReason): User {
            $lockedAccount = User::query()->lockForUpdate()->find($account->getKey());
            if ($lockedAccount === null) {
                throw AccountStatusTransitionException::unpersistedAccount();
            }

            if (! User::query()->whereKey($actor->getKey())->exists()) {
                throw AccountStatusTransitionException::unpersistedActor();
            }

            $from = $lockedAccount->status;
            if (! $from->canTransitionTo($target)) {
                throw AccountStatusTransitionException::invalidTransition($from->value, $target->value);
            }

            $occurredAt = now();
            $auditReason = $target === AccountStatus::Suspended
                ? $normalizedReason
                : $lockedAccount->suspension_reason;

            $lockedAccount->forceFill($this->attributesFor(
                $lockedAccount,
                $target,
                $normalizedReason,
                $occurredAt,
            ))->save();

            $metadata = [
                'target_user_id' => $lockedAccount->getKey(),
                'from_status' => $from->value,
                'to_status' => $target->value,
                'occurred_at' => $occurredAt->toIso8601String(),
            ];
            if ($auditReason !== null) {
                $metadata['suspension_reason'] = $auditReason;
            }

            $this->auditLogger->log(
                'user.status.transitioned',
                $lockedAccount,
                $metadata,
                $actor->getKey(),
            );

            return $lockedAccount->refresh();
        });
    }

    private function ensurePersisted(User $account, User $actor): void
    {
        if (! $account->exists || $account->getKey() === null) {
            throw AccountStatusTransitionException::unpersistedAccount();
        }

        if (! $actor->exists || $actor->getKey() === null) {
            throw AccountStatusTransitionException::unpersistedActor();
        }
    }

    private function normalizeSuspensionReason(?string $reason): string
    {
        $normalized = Str::squish($reason ?? '');
        if ($normalized === '') {
            throw AccountStatusTransitionException::suspensionReasonRequired();
        }

        if (strlen($normalized) > self::MAX_SUSPENSION_REASON_BYTES) {
            throw AccountStatusTransitionException::suspensionReasonTooLong();
        }

        return $normalized;
    }

    private function attributesFor(
        User $account,
        AccountStatus $target,
        ?string $reason,
        CarbonInterface $occurredAt,
    ): array {
        return match ($target) {
            AccountStatus::PendingApproval => [
                'status' => $target,
            ],
            AccountStatus::Active => [
                'status' => $target,
                'activated_at' => $account->status === AccountStatus::PendingApproval
                    ? $occurredAt
                    : ($account->activated_at ?? $occurredAt),
                'suspended_at' => null,
                'suspension_reason' => null,
                'archived_at' => null,
            ],
            AccountStatus::Suspended => [
                'status' => $target,
                'suspended_at' => $occurredAt,
                'suspension_reason' => $reason,
                'archived_at' => null,
            ],
            AccountStatus::Archived => [
                'status' => $target,
                'archived_at' => $occurredAt,
            ],
            AccountStatus::PendingEmail => throw AccountStatusTransitionException::invalidTransition(
                $account->status->value,
                $target->value,
            ),
        };
    }
}
