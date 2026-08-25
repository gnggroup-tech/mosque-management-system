<?php

namespace Tests\Feature\Communication;

use App\Enums\MosqueMembershipType;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\CouncilMember;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Services\AnnouncementDistributionService;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReliableAnnouncementReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_national_audiences_include_only_active_eligible_accounts(): void
    {
        $publisher = $this->actor('superadmin');
        $superadmin = $this->actor('superadmin');
        $admin = $this->actor('admin');
        $plain = $this->actor('user');
        $faithful = $this->actor('user');
        $mosque = $this->mosque('NATIONAL');
        $this->faithful($faithful, $mosque, $publisher);

        foreach ([
            User::factory()->pendingEmail()->create(),
            User::factory()->pendingApproval()->create(),
            User::factory()->suspended()->create(),
            User::factory()->archived()->create(),
        ] as $inactive) {
            $inactive->assignRole('admin');
            $this->faithful($inactive, $mosque, $publisher);
        }
        $inactiveFaithfulAccount = $this->actor('user');
        $this->faithful($inactiveFaithfulAccount, $mosque, $publisher, 'inactive');

        $all = $this->announcement($publisher, 'all');
        $administrators = $this->announcement($publisher, 'administrators');
        $faithfulAnnouncement = $this->announcement($publisher, 'faithful');

        $this->publish($publisher, $all)->assertJsonPath('receipts_count', 6);
        $this->assertSame(
            collect([$publisher, $superadmin, $admin, $plain, $faithful, $inactiveFaithfulAccount])->pluck('id')->sort()->values()->all(),
            $this->recipientIds($all),
        );

        $this->publish($publisher, $administrators)->assertJsonPath('receipts_count', 3);
        $this->assertSame(
            collect([$publisher, $superadmin, $admin])->pluck('id')->sort()->values()->all(),
            $this->recipientIds($administrators),
        );

        $this->publish($publisher, $faithfulAnnouncement)->assertJsonPath('receipts_count', 1);
        $this->assertSame([$faithful->id], $this->recipientIds($faithfulAnnouncement));
    }

    public function test_local_administrator_audience_requires_complete_canonical_authority(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $legacy = $this->actor('admin');
        $roleOnly = $this->actor('admin');
        $membershipOnly = $this->actor('user');
        $memberOnly = $this->actor('admin');
        $suspended = User::factory()->suspended()->create();
        $suspended->assignRole('admin');
        $superadmin = $this->actor('superadmin');
        $mosque = $this->mosque('LOCAL-ADMIN', $primary);
        $this->membership($secondary, $mosque, MosqueMembershipType::Administrator);
        $this->membership($membershipOnly, $mosque, MosqueMembershipType::Administrator);
        $this->membership($memberOnly, $mosque, MosqueMembershipType::Member);
        $this->membership($suspended, $mosque, MosqueMembershipType::Administrator);
        $mosque->forceFill(['admin_id' => $legacy->id])->save();

        $announcement = $this->announcement($secondary, 'administrators', $mosque);
        $this->publish($secondary, $announcement)->assertJsonPath('receipts_count', 2);

        $this->assertSame(collect([$primary, $secondary])->pluck('id')->sort()->values()->all(), $this->recipientIds($announcement));
        foreach ([$legacy, $roleOnly, $membershipOnly, $memberOnly, $suspended, $superadmin] as $excluded) {
            $this->assertNotContains($excluded->id, $this->recipientIds($announcement));
        }
    }

    public function test_local_faithful_audience_excludes_other_mosques_and_inactive_records(): void
    {
        $admin = $this->actor('admin');
        $otherAdmin = $this->actor('admin');
        $mosque = $this->mosque('LOCAL-FAITHFUL', $admin);
        $otherMosque = $this->mosque('OTHER-FAITHFUL', $otherAdmin);
        $local = $this->actor('user');
        $other = $this->actor('user');
        $inactiveRecord = $this->actor('user');
        $councilOnly = $this->actor('user');
        $suspended = User::factory()->suspended()->create();
        $suspended->assignRole('user');
        $this->faithful($local, $mosque, $admin);
        $this->faithful($other, $otherMosque, $otherAdmin);
        $this->faithful($inactiveRecord, $mosque, $admin, 'inactive');
        $this->faithful($suspended, $mosque, $admin);
        $council = MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Local council',
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-01-01',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => $councilOnly->id,
            'function' => 'imam',
            'started_at' => '2026-01-01',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $announcement = $this->announcement($admin, 'faithful', $mosque);
        $this->publish($admin, $announcement)->assertJsonPath('receipts_count', 1);
        $this->assertSame([$local->id], $this->recipientIds($announcement));
    }

    public function test_local_all_is_unique_and_snapshot_is_frozen(): void
    {
        $admin = $this->actor('admin');
        $dual = $this->actor('admin');
        $faithful = $this->actor('user');
        $mosque = $this->mosque('SNAPSHOT', $admin);
        $this->membership($dual, $mosque, MosqueMembershipType::Administrator);
        $this->faithful($dual, $mosque, $admin);
        $this->faithful($faithful, $mosque, $admin);
        $announcement = $this->announcement($admin, 'all', $mosque);

        $this->publish($admin, $announcement)->assertJsonPath('receipts_count', 3);
        $this->assertSame(3, $announcement->receipts()->count());
        $this->assertSame(3, $announcement->receipts()->distinct()->count('user_id'));
        $availableAt = $announcement->receipts()->where('user_id', $dual->id)->sole()->available_at;

        $late = $this->actor('admin');
        $this->membership($late, $mosque, MosqueMembershipType::Administrator);
        $dual->removeRole('admin');
        $dual->mosqueMemberships()->delete();

        $this->publish($admin, $announcement->fresh())->assertJsonPath('receipts_count', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'announcement.distributed')->count());
        $this->assertDatabaseMissing('announcement_receipts', ['announcement_id' => $announcement->id, 'user_id' => $late->id]);
        $this->assertDatabaseHas('announcement_receipts', ['announcement_id' => $announcement->id, 'user_id' => $dual->id]);
        $this->assertTrue($availableAt->equalTo($announcement->receipts()->where('user_id', $dual->id)->sole()->available_at));
    }

    public function test_distribution_rolls_back_publication_receipts_and_audits_on_failure(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('ROLLBACK', $admin);
        $announcement = $this->announcement($admin, 'administrators', $mosque);
        $auditCount = DB::table('audit_logs')->count();
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('announcement.distributed', Mockery::type(Announcement::class), Mockery::on(
                fn (array $metadata): bool => array_keys($metadata) === ['receipts_count'],
            ))
            ->andThrow(new RuntimeException('audit unavailable'));

        try {
            (new AnnouncementDistributionService($logger))->publish($announcement);
            $this->fail('Distribution should roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $announcement->refresh();
        $this->assertSame('draft', $announcement->status);
        $this->assertNull($announcement->published_at);
        $this->assertDatabaseMissing('announcement_receipts', ['announcement_id' => $announcement->id]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_availability_and_reading_are_distinct_and_receipts_are_private(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('PRIVATE', $admin);
        $first = $this->actor('user');
        $second = $this->actor('user');
        $outsider = $this->actor('user');
        $this->faithful($first, $mosque, $admin);
        $this->faithful($second, $mosque, $admin);
        $announcement = $this->announcement($admin, 'faithful', $mosque);
        $this->publish($admin, $announcement);

        $receipt = $announcement->receipts()->where('user_id', $first->id)->sole();
        $this->assertNotNull($receipt->available_at);
        $this->assertNull($receipt->read_at);
        $this->actingAs($first)->postJson(route('admin.announcements.read', $announcement))
            ->assertOk()
            ->assertJsonPath('user_id', $first->id);
        $this->assertNotNull($receipt->fresh()->read_at);
        $this->assertNull($announcement->receipts()->where('user_id', $second->id)->sole()->read_at);

        $response = $this->actingAs($outsider)->getJson(route('admin.announcements.show', $announcement))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('admin.announcements.read', $announcement))->assertNotFound();
        foreach ([$first->email, $second->email, $first->name, $second->name] as $pii) {
            $this->assertStringNotContainsString($pii, $response->getContent());
        }
    }

    public function test_legacy_delivered_at_is_backfilled_without_changing_its_value(): void
    {
        $publisher = $this->actor('superadmin');
        $recipient = $this->actor('user');
        $announcement = $this->announcement($publisher, 'all');
        $legacyTimestamp = '2026-01-15 12:30:00';

        Schema::table('announcement_receipts', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'read_at', 'available_at']);
            $table->dropColumn('available_at');
        });
        DB::table('announcement_receipts')->insert([
            'announcement_id' => $announcement->id,
            'user_id' => $recipient->id,
            'delivered_at' => $legacyTimestamp,
            'read_at' => null,
            'created_at' => $legacyTimestamp,
            'updated_at' => $legacyTimestamp,
        ]);

        $migration = require database_path('migrations/2026_08_25_020000_add_available_at_to_announcement_receipts.php');
        $migration->up();

        $receipt = AnnouncementReceipt::query()->sole();
        $this->assertSame($legacyTimestamp, $receipt->delivered_at->format('Y-m-d H:i:s'));
        $this->assertSame($legacyTimestamp, $receipt->available_at->format('Y-m-d H:i:s'));
        $this->assertNull($receipt->read_at);
    }

    public function test_large_audience_is_distributed_with_a_constant_query_count_and_minimal_audit(): void
    {
        $publisher = $this->actor('superadmin');
        User::factory()->count(150)->create();
        $announcement = $this->announcement($publisher, 'all');
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AnnouncementDistributionService::class)->publish($announcement);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(7, count($queries));
        $this->assertSame(151, $announcement->receipts()->count());
        $audit = DB::table('audit_logs')->where('event', 'announcement.distributed')->latest('id')->first();
        $metadata = json_decode($audit->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['receipts_count' => 151], $metadata);
        $this->assertStringNotContainsString('@', $audit->metadata);
    }

    private function publish(User $actor, Announcement $announcement): TestResponse
    {
        return $this->actingAs($actor)
            ->postJson(route('admin.announcements.publish', $announcement))
            ->assertOk();
    }

    private function announcement(User $creator, string $audience, ?Mosque $mosque = null): Announcement
    {
        return Announcement::query()->create([
            'mosque_id' => $mosque?->id,
            'title' => 'Internal announcement',
            'body' => 'Internal content',
            'type' => 'official',
            'priority' => 'normal',
            'audience' => $audience,
            'status' => 'draft',
            'visible_until' => now()->addDay(),
            'created_by' => $creator->id,
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code, ?User $primary = null): Mosque
    {
        $mosque = Mosque::query()->create([
            'code' => $code,
            'name' => 'Mosque '.$code,
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'admin_id' => $primary?->id,
        ]);
        if ($primary !== null) {
            $this->membership($primary, $mosque, MosqueMembershipType::Administrator);
        }

        return $mosque;
    }

    private function membership(User $user, Mosque $mosque, MosqueMembershipType $type): MosqueMembership
    {
        return MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $user->id,
            'membership_type' => $type,
        ]);
    }

    private function faithful(User $user, Mosque $mosque, User $creator, string $status = 'active'): Faithful
    {
        return Faithful::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $user->id,
            'registration_number' => 'FAI-'.str()->random(12),
            'first_name' => 'Internal',
            'last_name' => 'Recipient',
            'joined_at' => now()->toDateString(),
            'status' => $status,
            'consent_at' => now(),
            'created_by' => $creator->id,
        ]);
    }

    private function recipientIds(Announcement $announcement): array
    {
        return $announcement->receipts()->orderBy('user_id')->pluck('user_id')->all();
    }
}
