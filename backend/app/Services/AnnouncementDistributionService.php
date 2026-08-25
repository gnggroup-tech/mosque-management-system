<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnouncementDistributionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function publish(Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($announcement): Announcement {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->getKey());

            if ($locked->status === 'published') {
                return $locked->loadCount('receipts');
            }

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'announcement' => __('This announcement cannot be published in its current status.'),
                ]);
            }

            $availableAt = now();
            $locked->forceFill([
                'status' => 'published',
                'published_at' => $availableAt,
                'visible_from' => $locked->visible_from ?? $availableAt,
            ])->save();

            $timestamp = $availableAt->toDateTimeString();
            $recipients = $this->recipientQuery($locked)->selectRaw(
                '? as announcement_id, users.id as user_id, ? as delivered_at, ? as available_at, ? as created_at, ? as updated_at',
                [$locked->getKey(), $timestamp, $timestamp, $timestamp, $timestamp],
            );

            DB::table('announcement_receipts')->insertOrIgnoreUsing([
                'announcement_id',
                'user_id',
                'delivered_at',
                'available_at',
                'created_at',
                'updated_at',
            ], $recipients);

            $receiptsCount = $locked->receipts()->count();
            $this->auditLogger->log('announcement.distributed', $locked, [
                'receipts_count' => $receiptsCount,
            ]);

            return $locked->setAttribute('receipts_count', $receiptsCount);
        });
    }

    public function recipientQuery(Announcement $announcement): Builder
    {
        $query = User::query()
            ->where('users.status', AccountStatus::Active->value)
            ->distinct();

        if ($announcement->mosque_id === null) {
            return match ($announcement->audience) {
                'administrators' => $this->administrators($query),
                'faithful' => $this->faithful($query),
                default => $query,
            };
        }

        $mosqueId = $announcement->mosque_id;

        return match ($announcement->audience) {
            'administrators' => $this->localAdministrators($query, $mosqueId),
            'faithful' => $this->faithful($query, $mosqueId),
            default => $query->where(function (Builder $scope) use ($mosqueId): void {
                $scope->where(fn (Builder $administrators) => $this->localAdministrators($administrators, $mosqueId))
                    ->orWhere(fn (Builder $faithful) => $this->faithful($faithful, $mosqueId));
            }),
        };
    }

    private function administrators(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $roles) => $roles
            ->whereIn('name', ['superadmin', 'admin']));
    }

    private function localAdministrators(Builder $query, int $mosqueId): Builder
    {
        return $query
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'admin'))
            ->whereHas('mosqueMemberships', fn (Builder $memberships) => $memberships
                ->where('mosque_id', $mosqueId)
                ->where('membership_type', MosqueMembershipType::Administrator->value));
    }

    private function faithful(Builder $query, ?int $mosqueId = null): Builder
    {
        return $query->whereHas('faithfulRecords', fn (Builder $faithful) => $faithful
            ->where('status', 'active')
            ->when($mosqueId !== null, fn (Builder $local) => $local->where('mosque_id', $mosqueId)));
    }
}
