<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Exceptions\AccountProvisioningException;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccountProvisioningService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function versionFor(User $account): string
    {
        $roles = $account->roles()->pluck('name')->sort()->values()->all();
        $memberships = MosqueMembership::query()
            ->where('user_id', $account->getKey())
            ->orderBy('mosque_id')
            ->get(['mosque_id', 'membership_type'])
            ->map(fn (MosqueMembership $membership): string => $membership->mosque_id.':'.$membership->membership_type->value)
            ->all();
        $primaryMosques = Mosque::query()
            ->where('admin_id', $account->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return hash('sha256', json_encode([$roles, $memberships, $primaryMosques], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{mosque_id: int, membership_type: string}>  $memberships
     * @param  list<int>  $primaryMosqueIds
     * @param  array<int|string, int|string>  $primaryReplacements
     */
    public function provision(
        User $account,
        User $actor,
        string $role,
        array $memberships,
        array $primaryMosqueIds,
        array $primaryReplacements,
        string $expectedVersion,
    ): User {
        return DB::transaction(function () use (
            $account,
            $actor,
            $role,
            $memberships,
            $primaryMosqueIds,
            $primaryReplacements,
            $expectedVersion,
        ): User {
            $lockedAccount = User::query()->lockForUpdate()->find($account->getKey());
            $lockedActor = User::query()->lockForUpdate()->find($actor->getKey());
            if ($lockedAccount === null || $lockedActor === null) {
                throw AccountProvisioningException::invalid();
            }

            $lockedAccount->load('roles');
            $lockedActor->load('roles', 'permissions');
            Gate::forUser($lockedActor)->authorize('provision', $lockedAccount);

            if ($lockedAccount->status !== AccountStatus::Active || ! in_array($role, ['admin', 'user'], true)) {
                throw AccountProvisioningException::invalid();
            }

            if (! hash_equals($this->versionFor($lockedAccount), $expectedVersion)) {
                throw AccountProvisioningException::invalid();
            }

            $desired = $this->normalizeMemberships($memberships);
            $current = MosqueMembership::query()
                ->where('user_id', $lockedAccount->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('mosque_id');
            $currentPrimary = Mosque::query()
                ->where('admin_id', $lockedAccount->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $affectedMosqueIds = collect(array_keys($desired))
                ->merge($current->keys())
                ->merge($currentPrimary->keys())
                ->merge($primaryMosqueIds)
                ->merge(array_keys($primaryReplacements))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            $mosques = Mosque::query()->whereKey($affectedMosqueIds)->lockForUpdate()->get()->keyBy('id');
            if ($mosques->count() !== $affectedMosqueIds->count()) {
                throw AccountProvisioningException::invalid();
            }

            $administratorMosqueIds = collect($desired)
                ->filter(fn (MosqueMembershipType $type): bool => $type === MosqueMembershipType::Administrator)
                ->keys();
            if (($role === 'admin' && $administratorMosqueIds->isEmpty())
                || ($role === 'user' && $administratorMosqueIds->isNotEmpty())) {
                throw AccountProvisioningException::invalid();
            }

            foreach ($primaryMosqueIds as $mosqueId) {
                if (($desired[(int) $mosqueId] ?? null) !== MosqueMembershipType::Administrator) {
                    throw AccountProvisioningException::invalid();
                }
            }

            $principalChanges = [];
            foreach ($currentPrimary as $mosqueId => $mosque) {
                if (($desired[(int) $mosqueId] ?? null) === MosqueMembershipType::Administrator
                    && in_array((int) $mosqueId, array_map('intval', $primaryMosqueIds), true)) {
                    continue;
                }

                $replacementId = isset($primaryReplacements[$mosqueId])
                    ? (int) $primaryReplacements[$mosqueId]
                    : 0;
                $this->validateReplacement((int) $mosqueId, $replacementId, $lockedAccount);
                $principalChanges[(int) $mosqueId] = [$lockedAccount->getKey(), $replacementId];
                $mosque->forceFill(['admin_id' => $replacementId])->save();
            }

            foreach (array_unique(array_map('intval', $primaryMosqueIds)) as $mosqueId) {
                $mosque = $mosques->get($mosqueId);
                if ($mosque->admin_id !== $lockedAccount->getKey()) {
                    $principalChanges[$mosqueId] = [$mosque->admin_id, $lockedAccount->getKey()];
                    $mosque->forceFill(['admin_id' => $lockedAccount->getKey()])->save();
                }
            }

            $previousRole = $lockedAccount->roles->pluck('name')->sort()->implode('|') ?: null;
            $lockedAccount->syncRoles([$role]);
            if ($previousRole !== $role) {
                $this->auditLogger->log('user.role.changed', $lockedAccount, [
                    'actor_id' => $lockedActor->getKey(),
                    'target_user_id' => $lockedAccount->getKey(),
                    'previous_role' => $previousRole,
                    'new_role' => $role,
                    'occurred_at' => now()->toIso8601String(),
                ], $lockedActor->getKey());
            }

            $this->synchronizeMemberships($lockedAccount, $lockedActor, $current, $desired);

            foreach ($principalChanges as $mosqueId => [$previous, $new]) {
                $this->auditLogger->log('user.mosque.membership.changed', $lockedAccount, [
                    'actor_id' => $lockedActor->getKey(),
                    'target_user_id' => $lockedAccount->getKey(),
                    'mosque_id' => $mosqueId,
                    'previous_primary_admin_id' => $previous,
                    'new_primary_admin_id' => $new,
                    'occurred_at' => now()->toIso8601String(),
                ], $lockedActor->getKey());
            }

            return $lockedAccount->fresh(['roles', 'mosqueMemberships.mosque']);
        });
    }

    /** @return array<int, MosqueMembershipType> */
    private function normalizeMemberships(array $memberships): array
    {
        $normalized = [];
        foreach ($memberships as $membership) {
            $mosqueId = (int) ($membership['mosque_id'] ?? 0);
            $type = MosqueMembershipType::tryFrom((string) ($membership['membership_type'] ?? ''));
            if ($mosqueId < 1 || $type === null || isset($normalized[$mosqueId])) {
                throw AccountProvisioningException::invalid();
            }
            $normalized[$mosqueId] = $type;
        }

        return $normalized;
    }

    private function validateReplacement(int $mosqueId, int $replacementId, User $account): void
    {
        if ($replacementId < 1 || $replacementId === $account->getKey()) {
            throw AccountProvisioningException::invalid();
        }

        $replacement = User::query()->lockForUpdate()->find($replacementId);
        if ($replacement === null || ! $replacement->isActive() || ! $replacement->hasRole('admin')) {
            throw AccountProvisioningException::invalid();
        }

        $validMembership = MosqueMembership::query()
            ->where('mosque_id', $mosqueId)
            ->where('user_id', $replacementId)
            ->where('membership_type', MosqueMembershipType::Administrator->value)
            ->lockForUpdate()
            ->exists();
        if (! $validMembership) {
            throw AccountProvisioningException::invalid();
        }
    }

    /** @param array<int, MosqueMembershipType> $desired */
    private function synchronizeMemberships(User $account, User $actor, $current, array $desired): void
    {
        $mosqueIds = $current->keys()->merge(array_keys($desired))->map(fn ($id): int => (int) $id)->unique()->sort();
        foreach ($mosqueIds as $mosqueId) {
            $existing = $current->get($mosqueId);
            $previous = $existing?->membership_type;
            $next = $desired[$mosqueId] ?? null;
            if ($previous === $next) {
                continue;
            }

            if ($next === null) {
                $existing->delete();
                $event = 'user.mosque.unassigned';
            } elseif ($existing === null) {
                MosqueMembership::query()->create([
                    'mosque_id' => $mosqueId,
                    'user_id' => $account->getKey(),
                    'membership_type' => $next,
                    'assigned_by' => $actor->getKey(),
                ]);
                $event = 'user.mosque.assigned';
            } else {
                $existing->forceFill([
                    'membership_type' => $next,
                    'assigned_by' => $actor->getKey(),
                ])->save();
                $event = 'user.mosque.membership.changed';
            }

            $this->auditLogger->log($event, $account, [
                'actor_id' => $actor->getKey(),
                'target_user_id' => $account->getKey(),
                'mosque_id' => $mosqueId,
                'previous_membership_type' => $previous?->value,
                'new_membership_type' => $next?->value,
                'occurred_at' => now()->toIso8601String(),
            ], $actor->getKey());
        }
    }
}
