<?php

namespace App\Services;

use App\Enums\MosqueMembershipType;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MosquePrimaryAdministratorService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function create(array $attributes, User $actor): Mosque
    {
        return DB::transaction(function () use ($attributes, $actor): Mosque {
            $administrator = $this->administrator($attributes['admin_id'] ?? null);
            $mosque = Mosque::query()->create($attributes);
            if ($administrator !== null) {
                $this->synchronizeCanonicalMembership($mosque, $administrator, $actor);
            }

            return $mosque;
        });
    }

    public function update(Mosque $mosque, array $attributes, User $actor): Mosque
    {
        return DB::transaction(function () use ($mosque, $attributes, $actor): Mosque {
            $lockedMosque = Mosque::query()->lockForUpdate()->findOrFail($mosque->getKey());
            $administrator = null;
            if (array_key_exists('admin_id', $attributes)) {
                abort_if($attributes['admin_id'] === null && $lockedMosque->admin_id !== null, 422, 'A primary administrator requires an explicit active replacement.');
                $administrator = $this->administrator($attributes['admin_id']);
            }

            $lockedMosque->update($attributes);
            if ($administrator !== null) {
                $this->synchronizeCanonicalMembership($lockedMosque, $administrator, $actor);
            }

            return $lockedMosque;
        });
    }

    private function administrator(mixed $administratorId): ?User
    {
        if ($administratorId === null) {
            return null;
        }

        $administrator = User::query()->lockForUpdate()->findOrFail((int) $administratorId);
        abort_unless($administrator->isActive() && $administrator->hasRole('admin'), 422, 'The selected user must be an active admin.');

        return $administrator;
    }

    private function synchronizeCanonicalMembership(Mosque $mosque, User $administrator, User $actor): void
    {
        $membership = MosqueMembership::query()
            ->where('mosque_id', $mosque->getKey())
            ->where('user_id', $administrator->getKey())
            ->lockForUpdate()
            ->first();
        $previous = $membership?->membership_type;
        if ($previous === MosqueMembershipType::Administrator) {
            return;
        }

        if ($membership === null) {
            MosqueMembership::query()->create([
                'mosque_id' => $mosque->getKey(),
                'user_id' => $administrator->getKey(),
                'membership_type' => MosqueMembershipType::Administrator,
                'assigned_by' => $actor->getKey(),
            ]);
            $event = 'user.mosque.assigned';
        } else {
            $membership->forceFill([
                'membership_type' => MosqueMembershipType::Administrator,
                'assigned_by' => $actor->getKey(),
            ])->save();
            $event = 'user.mosque.membership.changed';
        }

        $this->auditLogger->log($event, $administrator, [
            'actor_id' => $actor->getKey(),
            'target_user_id' => $administrator->getKey(),
            'mosque_id' => $mosque->getKey(),
            'previous_membership_type' => $previous?->value,
            'new_membership_type' => MosqueMembershipType::Administrator->value,
            'occurred_at' => now()->toIso8601String(),
        ], $actor->getKey());
    }
}
