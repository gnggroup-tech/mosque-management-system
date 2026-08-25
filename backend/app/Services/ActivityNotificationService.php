<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Jobs\SendActivityNotifications;
use App\Models\Activity;
use App\Models\ActivityNotificationDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ActivityNotificationService
{
    public const TYPE_REMINDER = 'reminder';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_CANCELLED = 'cancelled';

    private const CLAIM_TIMEOUT_SECONDS = 300;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(Activity $activity, array $data): Activity
    {
        return DB::transaction(function () use ($activity, $data): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->getKey());
            if (in_array($locked->status, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'activity' => __('This activity can no longer be changed.'),
                ]);
            }

            if (array_key_exists('capacity', $data) && $data['capacity'] !== null
                && $locked->registrations()->count() > $data['capacity']) {
                throw ValidationException::withMessages([
                    'capacity' => __('The capacity is lower than the number of registrations.'),
                ]);
            }

            $importantChange = $locked->status === 'published'
                && $this->hasImportantChange($locked, $data);
            $locked->fill($data);
            if ($importantChange) {
                $locked->forceFill([
                    'notification_version' => $locked->notification_version + 1,
                    'reminder_queue_claimed_at' => null,
                    'reminder_queued_at' => null,
                ]);
            }
            $locked->save();

            if ($importantChange) {
                $this->prepareBatch($locked, self::TYPE_UPDATED);
            } elseif ($locked->status === 'published') {
                $this->prepareFailedBatch($locked, self::TYPE_UPDATED);
            }

            return $locked->fresh();
        });
    }

    public function cancel(Activity $activity): Activity
    {
        return DB::transaction(function () use ($activity): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->getKey());
            if ($locked->status === 'cancelled') {
                $this->prepareFailedBatch($locked, self::TYPE_CANCELLED);

                return $locked;
            }
            if (! in_array($locked->status, ['draft', 'published'], true)) {
                throw ValidationException::withMessages([
                    'activity' => __('This activity cannot be cancelled in its current status.'),
                ]);
            }

            $wasPublished = $locked->status === 'published';
            $locked->forceFill([
                'status' => 'cancelled',
                'notification_version' => $wasPublished
                    ? $locked->notification_version + 1
                    : $locked->notification_version,
                'reminder_queue_claimed_at' => null,
                'reminder_queued_at' => null,
            ])->save();

            if ($wasPublished) {
                $this->prepareBatch($locked, self::TYPE_CANCELLED);
            }

            return $locked->fresh();
        });
    }

    public function queueDueReminders(?CarbonInterface $reference = null): int
    {
        $now = CarbonImmutable::instance($reference ?? now(config('app.timezone')))
            ->timezone(config('app.timezone'));
        $activityIds = Activity::query()
            ->where('status', 'published')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours(24))
            ->whereNull('reminder_queued_at')
            ->where(function (Builder $claims) use ($now): void {
                $claims->whereNull('reminder_queue_claimed_at')
                    ->orWhere('reminder_queue_claimed_at', '<=', $now->subSeconds(self::CLAIM_TIMEOUT_SECONDS));
            })
            ->orderBy('id')
            ->pluck('id');

        $queued = 0;
        foreach ($activityIds as $activityId) {
            $prepared = DB::transaction(function () use ($activityId, $now): bool {
                $activity = Activity::query()->lockForUpdate()->find($activityId);
                if ($activity === null
                    || $activity->status !== 'published'
                    || ! $activity->starts_at->isAfter($now)
                    || $activity->starts_at->isAfter($now->addHours(24))
                    || $activity->reminder_queued_at !== null
                    || ($activity->reminder_queue_claimed_at !== null
                        && $activity->reminder_queue_claimed_at->isAfter($now->subSeconds(self::CLAIM_TIMEOUT_SECONDS)))) {
                    return false;
                }

                return $this->prepareBatch($activity, self::TYPE_REMINDER);
            });
            $queued += $prepared ? 1 : 0;
        }

        return $queued;
    }

    public function markQueued(int $activityId, string $type, int $version): bool
    {
        return DB::transaction(function () use ($activityId, $type, $version): bool {
            $activity = Activity::query()->lockForUpdate()->find($activityId);
            if (! $this->jobIsCurrent($activity, $type, $version)) {
                return false;
            }

            $query = ActivityNotificationDelivery::query()
                ->where('activity_id', $activityId)
                ->where('type', $type)
                ->where('version', $version)
                ->whereNull('sent_at')
                ->whereNull('skipped_at');
            $newlyQueued = (clone $query)->whereNull('queued_at')->count();
            if ($query->count() === 0) {
                return false;
            }

            $query->update([
                'queue_claimed_at' => null,
                'queued_at' => now(config('app.timezone')),
                'failed_at' => null,
                'updated_at' => now(config('app.timezone')),
            ]);
            if ($type === self::TYPE_REMINDER) {
                $activity->forceFill([
                    'reminder_queue_claimed_at' => null,
                    'reminder_queued_at' => $activity->reminder_queued_at ?? now(config('app.timezone')),
                ])->save();
            }
            if ($newlyQueued > 0) {
                $this->auditLogger->log('activity.notifications.queued', $activity, [
                    'type' => $type,
                    'version' => $version,
                    'recipients_count' => $newlyQueued,
                ]);
            }

            return true;
        });
    }

    public function sendBatch(
        int $activityId,
        string $type,
        int $version,
        int $attempt,
        ActivityNotificationMailSender $sender,
    ): bool {
        return DB::transaction(function () use ($activityId, $type, $version, $attempt, $sender): bool {
            $activity = Activity::query()->lockForUpdate()->find($activityId);
            if (! $this->jobIsCurrent($activity, $type, $version)) {
                return true;
            }

            $deliveries = ActivityNotificationDelivery::query()
                ->with('user')
                ->where('activity_id', $activityId)
                ->where('type', $type)
                ->where('version', $version)
                ->lockForUpdate()
                ->get();
            $registered = DB::table('activity_registrations')
                ->where('activity_id', $activityId)
                ->pluck('user_id')
                ->flip();
            $allSucceeded = true;

            foreach ($deliveries as $delivery) {
                if ($delivery->sent_at !== null || $delivery->skipped_at !== null) {
                    continue;
                }
                $delivery->attempts = max($delivery->attempts, $attempt);
                if ($delivery->user === null || $delivery->user->status !== AccountStatus::Active) {
                    $delivery->forceFill(['skipped_at' => now(config('app.timezone')), 'skip_reason' => 'account_inactive'])->save();

                    continue;
                }
                if (! $registered->has($delivery->user_id)) {
                    $delivery->forceFill(['skipped_at' => now(config('app.timezone')), 'skip_reason' => 'registration_missing'])->save();

                    continue;
                }

                try {
                    $sender->send($delivery->user, $activity, $type);
                    $delivery->forceFill([
                        'sent_at' => now(config('app.timezone')),
                        'failed_at' => null,
                    ])->save();
                } catch (Throwable) {
                    $delivery->save();
                    $allSucceeded = false;
                }
            }

            return $allSucceeded;
        });
    }

    public function markFinalFailures(int $activityId, string $type, int $version, int $attempts): void
    {
        DB::transaction(function () use ($activityId, $type, $version, $attempts): void {
            $activity = Activity::query()->lockForUpdate()->find($activityId);
            if (! $this->jobIsCurrent($activity, $type, $version)) {
                return;
            }
            ActivityNotificationDelivery::query()
                ->where('activity_id', $activityId)
                ->where('type', $type)
                ->where('version', $version)
                ->whereNull('sent_at')
                ->whereNull('skipped_at')
                ->update([
                    'attempts' => $attempts,
                    'failed_at' => now(config('app.timezone')),
                    'updated_at' => now(config('app.timezone')),
                ]);
        });
    }

    private function prepareBatch(Activity $activity, string $type): bool
    {
        $timestamp = now(config('app.timezone'))->toDateTimeString();
        $recipients = DB::table('users')
            ->join('activity_registrations', 'activity_registrations.user_id', '=', 'users.id')
            ->where('activity_registrations.activity_id', $activity->id)
            ->where('users.status', AccountStatus::Active->value)
            ->selectRaw('? as activity_id, users.id as user_id, ? as type, ? as version, ? as queue_claimed_at, ? as created_at, ? as updated_at', [
                $activity->id, $type, $activity->notification_version, $timestamp, $timestamp, $timestamp,
            ]);
        DB::table('activity_notification_deliveries')->insertOrIgnoreUsing([
            'activity_id', 'user_id', 'type', 'version', 'queue_claimed_at', 'created_at', 'updated_at',
        ], $recipients);

        $deliveries = ActivityNotificationDelivery::query()
            ->where('activity_id', $activity->id)
            ->where('type', $type)
            ->where('version', $activity->notification_version)
            ->whereNull('sent_at')
            ->whereNull('skipped_at');
        if (! $deliveries->exists()) {
            return false;
        }
        $deliveries->update(['queue_claimed_at' => $timestamp, 'failed_at' => null, 'updated_at' => $timestamp]);
        if ($type === self::TYPE_REMINDER) {
            $activity->forceFill(['reminder_queue_claimed_at' => $timestamp])->save();
        }

        $this->dispatchAfterCommit($activity->id, $type, $activity->notification_version);

        return true;
    }

    private function prepareFailedBatch(Activity $activity, string $type): bool
    {
        $timestamp = now(config('app.timezone'))->toDateTimeString();
        $deliveries = ActivityNotificationDelivery::query()
            ->where('activity_id', $activity->id)
            ->where('type', $type)
            ->where('version', $activity->notification_version)
            ->whereNotNull('failed_at')
            ->whereNull('sent_at')
            ->whereNull('skipped_at');
        if (! $deliveries->exists()) {
            return false;
        }
        $deliveries->update([
            'queue_claimed_at' => $timestamp,
            'failed_at' => null,
            'updated_at' => $timestamp,
        ]);
        $this->dispatchAfterCommit($activity->id, $type, $activity->notification_version);

        return true;
    }

    private function dispatchAfterCommit(int $activityId, string $type, int $version): void
    {
        DB::afterCommit(function () use ($activityId, $type, $version): void {
            try {
                Bus::dispatch(new SendActivityNotifications($activityId, $type, $version));
                $this->markQueued($activityId, $type, $version);
            } catch (Throwable) {
                $this->markQueueFailure($activityId, $type, $version);
                throw new RuntimeException('Activity notifications could not be queued.');
            }
        });
    }

    private function markQueueFailure(int $activityId, string $type, int $version): void
    {
        ActivityNotificationDelivery::query()
            ->where('activity_id', $activityId)
            ->where('type', $type)
            ->where('version', $version)
            ->whereNull('sent_at')
            ->update([
                'queue_claimed_at' => null,
                'failed_at' => now(config('app.timezone')),
                'updated_at' => now(config('app.timezone')),
            ]);
        if ($type === self::TYPE_REMINDER) {
            Activity::query()->whereKey($activityId)->where('notification_version', $version)
                ->update(['reminder_queue_claimed_at' => null, 'updated_at' => now(config('app.timezone'))]);
        }
    }

    private function jobIsCurrent(?Activity $activity, string $type, int $version): bool
    {
        if ($activity === null || $activity->notification_version !== $version) {
            return false;
        }

        return match ($type) {
            self::TYPE_CANCELLED => $activity->status === 'cancelled',
            self::TYPE_UPDATED => $activity->status === 'published',
            self::TYPE_REMINDER => $activity->status === 'published'
                && $activity->starts_at->isAfter(now(config('app.timezone'))),
            default => false,
        };
    }

    private function hasImportantChange(Activity $activity, array $data): bool
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)
                && $activity->{$field}->format('Y-m-d H:i:s') !== CarbonImmutable::parse($data[$field], config('app.timezone'))->format('Y-m-d H:i:s')) {
                return true;
            }
        }

        return array_key_exists('location', $data)
            && ($activity->location ?? '') !== ($data['location'] ?? '');
    }
}
