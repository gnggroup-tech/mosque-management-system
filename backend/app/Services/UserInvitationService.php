<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\InvitationException;
use App\Models\User;
use App\Models\UserInvitation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserInvitationService
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly AccountStatusTransitionService $transitionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return array{invitation: UserInvitation, token: string} */
    public function invite(array $attributes, User $actor): array
    {
        return DB::transaction(function () use ($attributes, $actor): array {
            if (User::query()->where('email', $attributes['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('An account already uses this email address.'),
                ]);
            }

            $token = $this->newToken();
            $occurredAt = now();
            $account = new User([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'locale' => $attributes['locale'],
                'password' => bin2hex(random_bytes(self::TOKEN_BYTES)),
            ]);
            $account->forceFill([
                'status' => AccountStatus::PendingEmail,
                'email_verified_at' => null,
                'remember_token' => null,
            ])->save();

            $invitation = UserInvitation::query()->create([
                'user_id' => $account->getKey(),
                'invited_by' => $actor->getKey(),
                'token_hash' => $this->hashToken($token),
                'expires_at' => $this->expiresAt(),
            ]);

            $this->auditLogger->log('user.invitation.created', $invitation, [
                'invitation_id' => $invitation->getKey(),
                'target_user_id' => $account->getKey(),
                'actor_id' => $actor->getKey(),
                'occurred_at' => $occurredAt->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ], $actor->getKey());

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    /** @return array{invitation: UserInvitation, token: string} */
    public function resend(User $account, User $actor): array
    {
        return DB::transaction(function () use ($account, $actor): array {
            $lockedAccount = User::query()->lockForUpdate()->find($account->getKey());
            if ($lockedAccount === null || $lockedAccount->status !== AccountStatus::PendingEmail) {
                throw InvitationException::accountUnavailable();
            }

            $invitation = UserInvitation::query()
                ->where('user_id', $lockedAccount->getKey())
                ->lockForUpdate()
                ->first();
            if ($invitation === null || $invitation->accepted_at !== null) {
                throw InvitationException::accountUnavailable();
            }

            $token = $this->newToken();
            $occurredAt = now();
            $invitation->forceFill([
                'token_hash' => $this->hashToken($token),
                'expires_at' => $this->expiresAt(),
            ])->save();

            $this->auditLogger->log('user.invitation.resent', $invitation, [
                'invitation_id' => $invitation->getKey(),
                'target_user_id' => $lockedAccount->getKey(),
                'actor_id' => $actor->getKey(),
                'occurred_at' => $occurredAt->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ], $actor->getKey());

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function validInvitation(string $token): UserInvitation
    {
        $hash = $this->hashToken($token);
        $invitation = UserInvitation::query()->with('user')->where('token_hash', $hash)->first();

        if (! $this->isValid($invitation, $hash)) {
            throw InvitationException::invalid();
        }

        return $invitation;
    }

    public function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password): User {
            $hash = $this->hashToken($token);
            $invitation = UserInvitation::query()->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $this->isValid($invitation, $hash)) {
                throw InvitationException::invalid();
            }

            $account = User::query()->lockForUpdate()->find($invitation->user_id);
            if ($account === null || $account->status !== AccountStatus::PendingEmail) {
                throw InvitationException::invalid();
            }

            $account->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
                'remember_token' => null,
            ])->save();

            $transitioned = $this->transitionService->transition(
                $account,
                AccountStatus::PendingApproval,
                $account,
            );

            $acceptedAt = now();
            $invitation->forceFill(['accepted_at' => $acceptedAt])->save();
            $this->auditLogger->log('user.invitation.accepted', $invitation, [
                'invitation_id' => $invitation->getKey(),
                'target_user_id' => $transitioned->getKey(),
                'occurred_at' => $acceptedAt->toIso8601String(),
            ]);

            return $transitioned->refresh();
        });
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function expiresAt(): CarbonInterface
    {
        $minutes = max(1, (int) config('invitations.expiration_minutes', 1440));

        return now()->addMinutes($minutes);
    }

    private function isValid(?UserInvitation $invitation, string $hash): bool
    {
        return $invitation !== null
            && hash_equals($invitation->token_hash, $hash)
            && $invitation->accepted_at === null
            && $invitation->expires_at->isFuture();
    }
}
