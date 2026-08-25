<?php

namespace Tests\Feature\Council;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Jobs\SendCouncilMeetingNotices;
use App\Models\CouncilMeeting;
use App\Models\CouncilMember;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Notifications\CouncilMeetingNoticeNotification;
use App\Services\CouncilMeetingNoticeMailSender;
use App\Services\CouncilMeetingNoticeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueuedCouncilMeetingNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_only_selected_eligible_participants_are_queued_without_exposing_pii(): void
    {
        Queue::fake();
        [$admin, $meeting, $participants] = $this->context([
            ['locale' => 'fr'],
            ['status' => AccountStatus::Suspended],
            ['member_status' => 'inactive'],
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.council-meetings.send-notice', $meeting))
            ->assertOk()
            ->assertJsonPath('status', 'convened')
            ->assertJsonPath('notice_summary.queued_count', 1)
            ->assertJsonPath('notice_summary.sent_count', 0)
            ->assertJsonCount(2, 'notice_summary.ineligible_participants');

        Queue::assertPushed(SendCouncilMeetingNotices::class, function (SendCouncilMeetingNotices $job) use ($participants): bool {
            return array_keys($job->participantVersions) === [$participants[0]->id];
        });
        $this->assertStringNotContainsString($participants[1]->member->user->email, $response->getContent());
        $this->assertStringNotContainsString($participants[1]->member->user->name, $response->getContent());
        $this->assertNull($participants[1]->fresh()->notice_queued_at);
        $this->assertNotNull($participants[0]->fresh()->notice_queued_at);

        $this->actingAs($admin)->postJson(route('admin.council-meetings.send-notice', $meeting))
            ->assertOk();
        Queue::assertPushed(SendCouncilMeetingNotices::class, 1);
    }

    public function test_no_eligible_recipient_returns_422_without_changing_the_meeting(): void
    {
        Queue::fake();
        [$admin, $meeting, $participants] = $this->context([
            ['status' => AccountStatus::Suspended],
            ['member_status' => 'inactive'],
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.council-meetings.send-notice', $meeting))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participants');

        Queue::assertNothingPushed();
        $meeting->refresh();
        $this->assertSame('draft', $meeting->status);
        $this->assertNull($meeting->notice_queue_claimed_at);
        $this->assertNull($meeting->notice_queued_at);
        $this->assertNull($meeting->notice_sent_at);
        foreach ($participants as $participant) {
            $this->assertStringNotContainsString($participant->member->user->email, $response->getContent());
        }
    }

    public function test_dispatch_waits_for_commit_and_a_rollback_produces_no_job(): void
    {
        config(['queue.default' => 'database']);
        [, $meeting] = $this->context([[]]);
        $job = new SendCouncilMeetingNotices($meeting->id, 1, [1 => 1]);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);

        DB::beginTransaction();
        app(CouncilMeetingNoticeService::class)->queue($meeting);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();

        $this->assertDatabaseCount('jobs', 0);
        $meeting->refresh();
        $this->assertSame('draft', $meeting->status);
        $this->assertSame(0, $meeting->notice_version);
    }

    public function test_database_and_failed_payloads_are_encrypted_and_free_of_pii(): void
    {
        config(['queue.default' => 'database']);
        $messages = [];
        Log::listen(function ($message) use (&$messages): void {
            $messages[] = $message->message.' '.json_encode($message->context);
        });
        [, $meeting, $participants] = $this->context([[]]);
        $account = $participants[0]->member->user;

        app(CouncilMeetingNoticeService::class)->queue($meeting);
        $payload = DB::table('jobs')->value('payload');
        $this->assertIsString($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $decoded['maxTries']);
        $this->assertSame('60,300,900', $decoded['backoff']);
        $serialized = Crypt::decryptString($decoded['data']['command']);
        $job = unserialize(unserialize($serialized));
        $this->assertInstanceOf(SendCouncilMeetingNotices::class, $job);

        foreach ([$account->email, $account->name, $meeting->title, $meeting->agenda] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $payload);
        }

        app(FailedJobProviderInterface::class)->log(
            'database',
            'default',
            $payload,
            new RuntimeException('One or more council meeting notice transports failed.'),
        );
        $failed = DB::table('failed_jobs')->firstOrFail();
        $observability = $failed->payload.' '.$failed->exception.' '.json_encode($messages, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($account->email, $observability);
        $this->assertStringNotContainsString($account->name, $observability);
    }

    public function test_success_tracks_individual_and_aggregate_state_and_preserves_locales(): void
    {
        Notification::fake();
        [, $meeting, $participants] = $this->context([
            ['locale' => 'fr'],
            ['locale' => 'en'],
            ['locale' => 'ar'],
        ]);

        $summary = app(CouncilMeetingNoticeService::class)->queue($meeting);
        $this->assertSame(3, $summary['queued_count']);
        $this->assertSame(3, $summary['sent_count']);

        foreach ($participants as $index => $participant) {
            $account = $participant->member->user;
            $locale = ['fr', 'en', 'ar'][$index];
            Notification::assertSentTo(
                $account,
                CouncilMeetingNoticeNotification::class,
                fn ($notification, array $channels, $notifiable, ?string $sentLocale): bool => $channels === ['mail']
                    && $sentLocale === $locale,
            );
            $participant->refresh();
            $this->assertNotNull($participant->notice_queued_at);
            $this->assertNotNull($participant->notice_sent_at);
            $this->assertSame(1, $participant->notice_attempts);
        }

        $meeting->refresh();
        $this->assertSame('convened', $meeting->status);
        $this->assertNotNull($meeting->notice_queued_at);
        $this->assertNotNull($meeting->notice_sent_at);
    }

    public function test_partial_failure_is_bounded_and_only_the_failure_is_requeued(): void
    {
        Queue::fake();
        [, $meeting, $participants] = $this->context([[], []]);
        app(CouncilMeetingNoticeService::class)->queue($meeting);
        $job = null;
        Queue::assertPushed(SendCouncilMeetingNotices::class, function (SendCouncilMeetingNotices $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(SendCouncilMeetingNotices::class, $job);
        $failingAccountId = $participants[1]->member->user_id;
        $sender = Mockery::mock(CouncilMeetingNoticeMailSender::class);
        $sender->shouldReceive('send')->andReturnUsing(function (User $account) use ($failingAccountId): void {
            if ($account->id === $failingAccountId) {
                throw new RuntimeException('private transport detail');
            }
        });

        foreach ([1, 2, 3] as $attempt) {
            $fakeJob = new FakeJob;
            $fakeJob->attempts = $attempt;
            $job->setJob($fakeJob);
            try {
                $job->handle(app(CouncilMeetingNoticeService::class), $sender);
                $this->fail('The partial transport failure should retry.');
            } catch (RuntimeException $exception) {
                $this->assertSame('One or more council meeting notice transports failed.', $exception->getMessage());
                $this->assertStringNotContainsString('private transport detail', $exception->getMessage());
            }
        }

        $job->failed(new RuntimeException('One or more council meeting notice transports failed.'));
        $this->assertNotNull($participants[0]->fresh()->notice_sent_at);
        $this->assertNull($participants[0]->fresh()->notice_failed_at);
        $this->assertNotNull($participants[1]->fresh()->notice_failed_at);
        $this->assertSame(3, $participants[1]->fresh()->notice_attempts);
        $this->assertNull($meeting->fresh()->notice_sent_at);

        app(CouncilMeetingNoticeService::class)->queue($meeting->fresh());
        $retryJob = null;
        Queue::assertPushed(SendCouncilMeetingNotices::class, function (SendCouncilMeetingNotices $retry) use ($participants, &$retryJob): bool {
            $matches = $retry->noticeVersion === 2
                && array_keys($retry->participantVersions) === [$participants[1]->id];
            if ($matches) {
                $retryJob = $retry;
            }

            return $matches;
        });
        $this->assertInstanceOf(SendCouncilMeetingNotices::class, $retryJob);
        $successfulSender = Mockery::mock(CouncilMeetingNoticeMailSender::class);
        $successfulSender->shouldReceive('send')->once();
        $fakeJob = new FakeJob;
        $fakeJob->attempts = 1;
        $retryJob->setJob($fakeJob);
        $retryJob->handle(app(CouncilMeetingNoticeService::class), $successfulSender);
        $this->assertNotNull($participants[1]->fresh()->notice_sent_at);
        $this->assertNotNull($meeting->fresh()->notice_sent_at);
    }

    public function test_obsolete_cancelled_and_inactive_deliveries_do_not_send(): void
    {
        Queue::fake();
        [, $meeting, $participants] = $this->context([[]]);
        app(CouncilMeetingNoticeService::class)->queue($meeting);
        $oldJob = new SendCouncilMeetingNotices($meeting->id, 1, [$participants[0]->id => 1]);
        $sender = Mockery::mock(CouncilMeetingNoticeMailSender::class);
        $sender->shouldNotReceive('send');

        $meeting->forceFill(['notice_version' => 2])->save();
        $oldJob->handle(app(CouncilMeetingNoticeService::class), $sender);
        $meeting->forceFill(['notice_version' => 1, 'status' => 'cancelled'])->save();
        $oldJob->handle(app(CouncilMeetingNoticeService::class), $sender);
        $meeting->forceFill(['status' => 'convened'])->save();
        $participants[0]->member->user->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->assertFalse(app(CouncilMeetingNoticeService::class)->sendParticipant(
            $meeting->id,
            1,
            $participants[0]->id,
            1,
            1,
            $sender,
        ));
        $this->assertNull($participants[0]->fresh()->notice_sent_at);
    }

    public function test_canonical_authorization_is_required_for_the_notice_action(): void
    {
        Queue::fake();
        [$primary, $meeting] = $this->context([[]]);
        $secondary = User::factory()->create();
        $secondary->assignRole('admin');

        $this->actingAs($secondary)
            ->postJson(route('admin.council-meetings.send-notice', $meeting))
            ->assertForbidden();

        MosqueMembership::query()->create([
            'mosque_id' => $meeting->council->mosque_id,
            'user_id' => $secondary->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);
        $this->actingAs($secondary)
            ->postJson(route('admin.council-meetings.send-notice', $meeting))
            ->assertOk();
        $this->assertNotSame($primary->id, $secondary->id);
    }

    /** @param list<array{locale?: string, status?: AccountStatus, member_status?: string}> $specifications */
    private function context(array $specifications): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'NOTICE-'.str()->random(8),
            'name' => 'Notice mosque',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'admin_id' => $admin->id,
        ]);
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $admin->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);
        $council = MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Notice council',
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-01-01',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $meeting = CouncilMeeting::query()->create([
            'mosque_council_id' => $council->id,
            'title' => 'Private meeting title',
            'agenda' => 'Private agenda',
            'scheduled_at' => now()->addDay(),
            'location' => 'Council room',
            'quorum_required' => 1,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $participants = collect($specifications)->map(function (array $specification) use ($council, $meeting, $admin) {
            $account = User::factory()->create([
                'locale' => $specification['locale'] ?? 'fr',
                'status' => $specification['status'] ?? AccountStatus::Active,
            ]);
            $member = CouncilMember::query()->create([
                'mosque_council_id' => $council->id,
                'user_id' => $account->id,
                'function' => 'member',
                'started_at' => '2026-01-01',
                'status' => $specification['member_status'] ?? 'active',
                'created_by' => $admin->id,
            ]);

            return $meeting->participants()->create(['council_member_id' => $member->id])
                ->load('member.user');
        })->all();

        return [$admin, $meeting->load('council.mosque'), $participants];
    }
}
