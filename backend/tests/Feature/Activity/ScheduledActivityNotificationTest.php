<?php

namespace Tests\Feature\Activity;

use App\Enums\MosqueMembershipType;
use App\Jobs\SendActivityNotifications;
use App\Models\Activity;
use App\Models\ActivityNotificationDelivery;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\ActivityNotificationMailSender;
use App\Services\ActivityNotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScheduledActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_scheduler_queues_one_reminder_in_the_robust_window_for_active_registrants_only(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        [, $activity] = $this->context($now->addHours(23));
        $active = $this->registered($activity, 'fr');
        $inactive = User::factory()->suspended()->create();
        $inactive->assignRole('user');
        $activity->registrations()->create(['user_id' => $inactive->id, 'registered_at' => $now]);

        $service = app(ActivityNotificationService::class);
        $this->assertSame(1, $service->queueDueReminders($now));
        $this->assertSame(0, $service->queueDueReminders($now->addMinutes(5)));
        Queue::assertPushed(SendActivityNotifications::class, 1);
        $this->assertDatabaseHas('activity_notification_deliveries', [
            'activity_id' => $activity->id,
            'user_id' => $active->id,
            'type' => 'reminder',
            'version' => 0,
        ]);
        $this->assertDatabaseMissing('activity_notification_deliveries', [
            'activity_id' => $activity->id,
            'user_id' => $inactive->id,
        ]);
        $this->assertNotNull($activity->fresh()->reminder_queued_at);
    }

    public function test_reminders_are_not_queued_too_early_too_late_cancelled_or_unpublished(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        foreach ([
            [$now->addHours(24)->addSecond(), 'published'],
            [$now, 'published'],
            [$now->subMinute(), 'published'],
            [$now->addHours(2), 'cancelled'],
            [$now->addHours(2), 'draft'],
        ] as [$startsAt, $status]) {
            [, $activity] = $this->context($startsAt, $status);
            $this->registered($activity);
        }

        $this->assertSame(0, app(ActivityNotificationService::class)->queueDueReminders($now));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('activity_notification_deliveries', 0);
    }

    public function test_success_uses_french_english_and_arabic_locales_and_tracks_transport_success(): void
    {
        Notification::fake();
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        [, $activity] = $this->context($now->addHours(20));
        $accounts = collect(['fr', 'en', 'ar'])->map(fn (string $locale) => $this->registered($activity, $locale));

        app(ActivityNotificationService::class)->queueDueReminders($now);

        foreach ($accounts as $account) {
            Notification::assertSentTo(
                $account,
                ActivityNotification::class,
                fn ($notification, array $channels, $notifiable, ?string $locale): bool => $channels === ['mail']
                    && $locale === $account->locale,
            );
        }
        $this->assertSame(3, ActivityNotificationDelivery::query()->whereNotNull('sent_at')->count());
        $this->assertSame(0, ActivityNotificationDelivery::query()->whereNotNull('failed_at')->count());
    }

    public function test_important_update_versions_jobs_while_descriptive_update_sends_nothing(): void
    {
        Queue::fake();
        [$admin, $activity] = $this->context(now()->addDays(2));
        $this->registered($activity);

        $this->actingAs($admin)->patchJson(route('admin.activities.update', $activity), [
            'description' => 'Description only',
        ])->assertOk();
        Queue::assertNothingPushed();
        $this->assertSame(0, $activity->fresh()->notification_version);

        $this->actingAs($admin)->patchJson(route('admin.activities.update', $activity), [
            'starts_at' => $activity->starts_at->addHour()->toDateTimeString(),
            'ends_at' => $activity->ends_at->addHour()->toDateTimeString(),
            'location' => 'New room',
        ])->assertOk();
        Queue::assertPushed(SendActivityNotifications::class, fn (SendActivityNotifications $job) => $job->type === 'updated' && $job->version === 1);
        $this->assertSame(1, $activity->fresh()->notification_version);
    }

    public function test_each_schedule_or_location_field_independently_triggers_one_versioned_notice(): void
    {
        Queue::fake();

        foreach (['starts_at', 'ends_at', 'location'] as $field) {
            [$admin, $activity] = $this->context(now()->addDays(3));
            $this->registered($activity);
            $value = match ($field) {
                'starts_at' => $activity->starts_at->addHour()->toDateTimeString(),
                'ends_at' => $activity->ends_at->addHour()->toDateTimeString(),
                default => 'Changed location',
            };
            $this->actingAs($admin)->patchJson(route('admin.activities.update', $activity), [$field => $value])
                ->assertOk();
            $this->assertSame(1, $activity->fresh()->notification_version);
            $this->assertDatabaseHas('activity_notification_deliveries', [
                'activity_id' => $activity->id,
                'type' => 'updated',
                'version' => 1,
            ]);
        }

        Queue::assertPushed(SendActivityNotifications::class, 3);
    }

    public function test_cancellation_is_idempotent_and_obsoletes_old_jobs(): void
    {
        Queue::fake();
        [$admin, $activity] = $this->context(now()->addDay());
        $active = $this->registered($activity);
        $inactive = User::factory()->suspended()->create();
        $inactive->assignRole('user');
        $activity->registrations()->create(['user_id' => $inactive->id, 'registered_at' => now()]);
        $oldJob = new SendActivityNotifications($activity->id, 'reminder', 0);

        $this->actingAs($admin)->postJson(route('admin.activities.cancel', $activity))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($admin)->postJson(route('admin.activities.cancel', $activity->fresh()))->assertOk()->assertJsonPath('status', 'cancelled');
        Queue::assertPushed(SendActivityNotifications::class, 1);
        Queue::assertPushed(SendActivityNotifications::class, fn (SendActivityNotifications $job) => $job->type === 'cancelled' && $job->version === 1);

        $sender = Mockery::mock(ActivityNotificationMailSender::class);
        $sender->shouldNotReceive('send');
        $oldJob->handle(app(ActivityNotificationService::class), $sender);
        $this->assertDatabaseHas('activity_notification_deliveries', [
            'activity_id' => $activity->id,
            'user_id' => $active->id,
            'type' => 'cancelled',
        ]);
        $this->assertDatabaseMissing('activity_notification_deliveries', [
            'activity_id' => $activity->id,
            'user_id' => $inactive->id,
        ]);
        $this->assertSame(1, ActivityNotificationDelivery::query()->where('activity_id', $activity->id)->count());
    }

    public function test_jobs_retry_three_times_record_final_failure_and_can_be_retried_idempotently(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        [, $activity] = $this->context($now->addHours(20));
        $this->registered($activity);
        app(ActivityNotificationService::class)->queueDueReminders($now);
        $job = null;
        Queue::assertPushed(SendActivityNotifications::class, function (SendActivityNotifications $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertSame([60, 300, 900], $job->backoff());
        $sender = Mockery::mock(ActivityNotificationMailSender::class);
        $sender->shouldReceive('send')->times(3)->andThrow(new RuntimeException('private transport detail'));

        foreach ([1, 2, 3] as $attempt) {
            $fakeJob = new FakeJob;
            $fakeJob->attempts = $attempt;
            $job->setJob($fakeJob);
            try {
                $job->handle(app(ActivityNotificationService::class), $sender);
                $this->fail('The transport should fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('One or more activity notification transports failed.', $exception->getMessage());
            }
        }
        $job->failed(new RuntimeException('One or more activity notification transports failed.'));
        $delivery = ActivityNotificationDelivery::query()->sole();
        $this->assertSame(3, $delivery->attempts);
        $this->assertNotNull($delivery->failed_at);

        $successful = Mockery::mock(ActivityNotificationMailSender::class);
        $successful->shouldReceive('send')->once();
        $fakeJob = new FakeJob;
        $fakeJob->attempts = 1;
        $job->setJob($fakeJob);
        $job->handle(app(ActivityNotificationService::class), $successful);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_encrypted_database_payload_and_observability_contain_no_pii(): void
    {
        config(['queue.default' => 'database']);
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        [, $activity] = $this->context($now->addHours(20));
        $account = $this->registered($activity);

        app(ActivityNotificationService::class)->queueDueReminders($now);
        $payload = DB::table('jobs')->value('payload');
        $this->assertIsString($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $decoded['maxTries']);
        $this->assertSame('60,300,900', $decoded['backoff']);
        $serialized = Crypt::decryptString($decoded['data']['command']);
        $this->assertInstanceOf(SendActivityNotifications::class, unserialize(unserialize($serialized)));
        foreach (array_filter([$account->email, $account->name, $activity->title, $activity->location]) as $pii) {
            $this->assertStringNotContainsString($pii, $payload);
        }
        app(FailedJobProviderInterface::class)->log('database', 'default', $payload, new RuntimeException('Activity notification transport failed.'));
        $failed = DB::table('failed_jobs')->firstOrFail();
        $this->assertStringNotContainsString($account->email, $failed->payload.$failed->exception);
        $this->assertStringNotContainsString($account->email, DB::table('audit_logs')->pluck('metadata')->implode(' '));
    }

    public function test_business_rollback_dispatches_no_after_commit_job(): void
    {
        Queue::fake();
        [, $activity] = $this->context(now()->addDay());
        $this->registered($activity);

        DB::beginTransaction();
        app(ActivityNotificationService::class)->update($activity, ['location' => 'Rolled back room']);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertNull($activity->fresh()->location);
        $this->assertDatabaseCount('activity_notification_deliveries', 0);
    }

    public function test_schedule_declares_five_minute_reminders_and_daily_locked_backup_without_execution(): void
    {
        $events = collect(app(Schedule::class)->events());
        $reminders = $events->first(fn ($event) => str_contains($event->command, 'sgar:activities:queue-reminders'));
        $backup = $events->first(fn ($event) => str_contains($event->command, 'sgar:backup:create'));

        $this->assertNotNull($reminders);
        $this->assertSame('*/5 * * * *', $reminders->expression);
        $this->assertTrue($reminders->withoutOverlapping);
        $this->assertFalse($reminders->onOneServer);
        $this->assertNotNull($backup);
        $this->assertSame('0 2 * * *', $backup->expression);
        $this->assertTrue($backup->withoutOverlapping);
        $this->assertFalse($backup->onOneServer);
        $this->assertSame('02:00', config('backup.schedule_time'));
    }

    public function test_large_recipient_snapshot_is_selected_without_recipient_query_growth(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-09-01 10:00:00', config('app.timezone'));
        [, $activity] = $this->context($now->addHours(20));
        $accounts = User::factory()->count(100)->create();
        $activity->registrations()->createMany($accounts->map(fn (User $account) => [
            'user_id' => $account->id,
            'registered_at' => $now,
        ])->all());
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(ActivityNotificationService::class)->queueDueReminders($now);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(18, $queryCount);
        $this->assertSame(100, ActivityNotificationDelivery::query()->count());
        Queue::assertPushed(SendActivityNotifications::class, 1);
    }

    private function context($startsAt, string $status = 'published'): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'ACT-'.str()->random(8), 'name' => 'Activity mosque',
            'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma',
            'status' => 'active', 'admin_id' => $admin->id,
        ]);
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id, 'user_id' => $admin->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);
        $activity = Activity::query()->create([
            'mosque_id' => $mosque->id,
            'title' => 'Registered activity',
            'type' => 'course',
            'description' => 'Description',
            'location' => null,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addHours(2),
            'capacity' => 500,
            'status' => $status,
            'registration_required' => true,
            'published_at' => $status === 'published' ? now() : null,
            'created_by' => $admin->id,
        ]);

        return [$admin, $activity];
    }

    private function registered(Activity $activity, string $locale = 'fr'): User
    {
        $account = User::factory()->create(['locale' => $locale]);
        $account->assignRole('user');
        $activity->registrations()->create(['user_id' => $account->id, 'registered_at' => now()]);

        return $account;
    }
}
