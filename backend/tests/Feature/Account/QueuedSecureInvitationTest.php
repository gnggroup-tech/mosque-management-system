<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Jobs\SendUserInvitationEmail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\AuditLogger;
use App\Services\BackupRestorePreparer;
use App\Services\UserInvitationDeliveryService;
use App\Services\UserInvitationMailSender;
use App\Services\UserInvitationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class QueuedSecureInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_invitation_is_queued_once_and_tracks_queue_acceptance(): void
    {
        Queue::fake();
        [$account, $invitation, $token] = $this->invitation();

        $delivery = app(UserInvitationDeliveryService::class);

        $this->assertTrue($delivery->queue($invitation, $account, $token));
        $this->assertFalse($delivery->queue($invitation->fresh(), $account, $token));

        Queue::assertPushed(SendUserInvitationEmail::class, 1);
        $invitation->refresh();
        $this->assertNotNull($invitation->queued_at);
        $this->assertNull($invitation->queue_claimed_at);
        $this->assertNull($invitation->sent_at);
        $this->assertNull($invitation->failed_at);
        $this->assertSame(0, $invitation->delivery_attempts);
        $this->assertSame(AccountStatus::PendingEmail, $account->refresh()->status);
    }

    public function test_business_rollback_dispatches_no_job_and_after_commit_job_is_discarded(): void
    {
        Queue::fake();
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $logger);

        try {
            app(UserInvitationService::class)->invite($this->attributes(), User::factory()->create());
            $this->fail('The transaction should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('users', ['email' => 'queued-invited@example.test']);
        $this->assertDatabaseCount('user_invitations', 0);

        $job = new SendUserInvitationEmail(999, 1, 'rollback-token', 'fr');
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);

        Queue::swap(app('queue'));
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        Bus::dispatch($job);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_database_and_failed_job_payloads_are_encrypted_and_observability_is_secret_free(): void
    {
        config(['queue.default' => 'database']);
        $messages = [];
        Log::listen(function ($message) use (&$messages): void {
            $messages[] = $message->message.' '.json_encode($message->context);
        });

        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');
        $response = $this->actingAs($actor)->post(
            route('admin.accounts.invitations.store'),
            $this->attributes('payload'),
        );
        $response->assertRedirect(route('admin.accounts.invitations.create'));
        $account = User::query()->where('email', 'queued-invited-payload@example.test')->sole();
        $invitation = $account->invitation()->sole();

        $payload = DB::table('jobs')->value('payload');
        $this->assertIsString($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $decoded['maxTries']);
        $this->assertSame('60,300,900', $decoded['backoff']);
        $serialized = Crypt::decryptString($decoded['data']['command']);
        $job = unserialize(unserialize($serialized));
        $this->assertInstanceOf(SendUserInvitationEmail::class, $job);
        $tokenProperty = new ReflectionProperty($job, 'token');
        $token = $tokenProperty->getValue($job);
        $this->assertIsString($token);
        $this->assertStringNotContainsString($token, $payload);

        app(FailedJobProviderInterface::class)->log(
            'database',
            'default',
            $payload,
            new RuntimeException('The invitation email transport failed.'),
        );
        $failed = DB::table('failed_jobs')->firstOrFail();
        $this->assertStringNotContainsString($token, $failed->payload);
        $this->assertStringNotContainsString($token, $failed->exception);
        $this->assertStringNotContainsString($token, json_encode($messages, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token, AuditLog::query()->pluck('metadata')->toJson());
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertNotSame($token, $invitation->fresh()->token_hash);
    }

    public function test_success_marks_sent_and_preserves_french_english_and_arabic_locales(): void
    {
        Notification::fake();

        foreach (['fr', 'en', 'ar'] as $locale) {
            [$account, $invitation, $token] = $this->invitation($locale, $locale);

            app(UserInvitationDeliveryService::class)->queue($invitation, $account, $token);

            Notification::assertSentTo(
                $account,
                UserInvitationNotification::class,
                fn ($notification, array $channels, $notifiable, ?string $sentLocale): bool => $channels === ['mail']
                    && $sentLocale === $locale,
            );
            $invitation->refresh();
            $this->assertNotNull($invitation->queued_at);
            $this->assertNotNull($invitation->sent_at);
            $this->assertNull($invitation->failed_at);
            $this->assertSame(1, $invitation->delivery_attempts);
        }
    }

    public function test_temporary_failures_are_bounded_and_final_failure_is_recorded(): void
    {
        [$account, $invitation, $token] = $this->invitation();
        $sender = Mockery::mock(UserInvitationMailSender::class);
        $sender->shouldReceive('send')->times(3)->andThrow(new RuntimeException($token));
        $job = new SendUserInvitationEmail($invitation->id, 1, $token, $account->locale);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());

        foreach ([1, 2, 3] as $attempt) {
            $fakeJob = new FakeJob;
            $fakeJob->attempts = $attempt;
            $job->setJob($fakeJob);

            try {
                $job->handle($sender);
                $this->fail('The mail transport should fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The invitation email transport failed.', $exception->getMessage());
                $this->assertStringNotContainsString($token, (string) $exception);
            }

            $this->assertSame($attempt, $invitation->refresh()->delivery_attempts);
            $this->assertNull($invitation->failed_at);
        }

        $job->failed(new RuntimeException('The invitation email transport failed.'));
        $this->assertNotNull($invitation->refresh()->failed_at);
        $this->assertSame(3, $invitation->delivery_attempts);
        $this->assertSame(AccountStatus::PendingEmail, $account->refresh()->status);
    }

    public function test_a_sent_delivery_version_cannot_send_twice(): void
    {
        Notification::fake();
        [$account, $invitation, $token] = $this->invitation();
        app(UserInvitationDeliveryService::class)->queue($invitation, $account, $token);
        $this->assertNotNull($invitation->refresh()->sent_at);

        $sender = Mockery::mock(UserInvitationMailSender::class);
        $sender->shouldNotReceive('send');
        (new SendUserInvitationEmail($invitation->id, 1, $token, $account->locale))->handle($sender);

        Notification::assertSentTo($account, UserInvitationNotification::class, 1);
        $this->assertSame(1, $invitation->refresh()->delivery_attempts);
    }

    public function test_resend_after_failure_rotates_token_and_obsoletes_the_old_job(): void
    {
        Notification::fake();
        [$account, $invitation, $oldToken, $actor] = $this->invitationWithActor();
        $oldJob = new SendUserInvitationEmail($invitation->id, 1, $oldToken, $account->locale);
        $invitation->forceFill([
            'queued_at' => now()->subMinute(),
            'failed_at' => now(),
            'delivery_attempts' => 3,
        ])->save();

        $resent = app(UserInvitationService::class)->resend($account, $actor);
        $this->assertNotSame($oldToken, $resent['token']);
        $this->assertSame(2, $resent['invitation']->delivery_version);
        $this->assertNull($resent['invitation']->queued_at);
        $this->assertNull($resent['invitation']->failed_at);
        $this->assertSame(0, $resent['invitation']->delivery_attempts);

        $sender = Mockery::mock(UserInvitationMailSender::class);
        $sender->shouldNotReceive('send');
        $oldJob->handle($sender);

        app(UserInvitationDeliveryService::class)->queue(
            $resent['invitation'],
            $account,
            $resent['token'],
        );

        $this->get(route('invitations.show', $oldToken))->assertNotFound();
        $this->get(route('invitations.show', $resent['token']))->assertOk();
        Notification::assertSentTo($account, UserInvitationNotification::class, 1);
    }

    public function test_restore_preparation_invalidates_pending_delivery_tracking(): void
    {
        $prepared = app(BackupRestorePreparer::class)->invitation([
            'id' => 42,
            'user_id' => 21,
            'invited_by' => 1,
            'token_hash' => hash('sha256', 'restored-token'),
            'expires_at' => now()->addDay()->toDateTimeString(),
            'accepted_at' => null,
            'delivery_version' => 7,
            'queue_claimed_at' => now()->toDateTimeString(),
            'queued_at' => now()->toDateTimeString(),
            'sent_at' => now()->toDateTimeString(),
            'failed_at' => now()->toDateTimeString(),
            'delivery_attempts' => 3,
        ]);

        $this->assertSame(8, $prepared['delivery_version']);
        $this->assertNull($prepared['queue_claimed_at']);
        $this->assertNull($prepared['queued_at']);
        $this->assertNull($prepared['sent_at']);
        $this->assertNull($prepared['failed_at']);
        $this->assertSame(0, $prepared['delivery_attempts']);
        $this->assertNotSame(hash('sha256', 'restored-token'), $prepared['token_hash']);
    }

    /** @return array{User, UserInvitation, string} */
    private function invitation(string $suffix = 'default', string $locale = 'fr'): array
    {
        [$account, $invitation, $token] = $this->invitationWithActor($suffix, $locale);

        return [$account, $invitation, $token];
    }

    /** @return array{User, UserInvitation, string, User} */
    private function invitationWithActor(string $suffix = 'default', string $locale = 'fr'): array
    {
        $actor = User::factory()->create();
        $result = app(UserInvitationService::class)->invite($this->attributes($suffix, $locale), $actor);
        $account = $result['invitation']->user()->firstOrFail();

        return [$account, $result['invitation'], $result['token'], $actor];
    }

    /** @return array{name: string, email: string, locale: string} */
    private function attributes(string $suffix = 'default', string $locale = 'fr'): array
    {
        return [
            'name' => 'Queued Invitation '.$suffix,
            'email' => 'queued-invited-'.$suffix.'@example.test',
            'locale' => $locale,
        ];
    }
}
