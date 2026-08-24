<?php

namespace Tests\Feature\Authorization;

use App\Enums\MosqueMembershipType;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\CouncilMeeting;
use App\Models\CouncilMember;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalCommunityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_secondary_canonical_administrator_has_local_scope_across_all_six_modules(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $other = $this->actor('admin');
        $localMosque = $this->mosque('LOCAL', $primary);
        $otherMosque = $this->mosque('OTHER', $other);
        $this->membership($secondary, $localMosque, MosqueMembershipType::Administrator);
        $localRecords = $this->records($localMosque, $primary, 'LOCAL');
        $outsideRecords = $this->records($otherMosque, $other, 'OTHER');

        foreach ($localRecords as $module => $record) {
            $this->actingAs($secondary)->getJson(route("admin.{$module}.index"))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $record->getKey())
                ->assertJsonMissing(['id' => $outsideRecords[$module]->getKey()]);
            $this->actingAs($secondary)->getJson(route("admin.{$module}.show", $record))->assertOk();
            $this->actingAs($secondary)->getJson(route("admin.{$module}.show", $outsideRecords[$module]))->assertForbidden();
        }
    }

    public function test_superadmin_retains_global_visibility_across_all_six_modules(): void
    {
        $superadmin = $this->actor('superadmin');
        $records = $this->records($this->mosque('GLOBAL'), $superadmin, 'GLOBAL');

        foreach ($records as $module => $record) {
            $this->actingAs($superadmin)->getJson(route("admin.{$module}.index"))
                ->assertOk()
                ->assertJsonCount(1, 'data');
            $this->actingAs($superadmin)->getJson(route("admin.{$module}.show", $record))->assertOk();
        }
    }

    public function test_secondary_canonical_administrator_can_perform_point_mutations_in_all_six_modules(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $mosque = $this->mosque('MUTATIONS', $primary);
        $this->membership($secondary, $mosque, MosqueMembershipType::Administrator);
        $records = $this->records($mosque, $primary, 'MUTATIONS');

        $this->actingAs($secondary)->postJson(route('admin.activities.publish', $records['activities']))
            ->assertOk()->assertJsonPath('status', 'published');
        $this->actingAs($secondary)->postJson(route('admin.announcements.publish', $records['announcements']))
            ->assertOk()->assertJsonPath('status', 'published');
        $this->actingAs($secondary)->patchJson(route('admin.councils.update', $records['councils']), ['notes' => 'Canonical update'])
            ->assertOk()->assertJsonPath('notes', 'Canonical update');
        $this->actingAs($secondary)->patchJson(route('admin.council-members.update', $records['council-members']), ['title' => 'Canonical title'])
            ->assertOk()->assertJsonPath('title', 'Canonical title');
        $this->actingAs($secondary)->postJson(route('admin.council-meetings.send-notice', $records['council-meetings']))
            ->assertOk()->assertJsonPath('status', 'convened');
        $this->actingAs($secondary)->patchJson(route('admin.faithful.update', $records['faithful']), ['occupation' => 'Canonical occupation'])
            ->assertOk()->assertJsonPath('occupation', 'Canonical occupation');
    }

    public function test_partial_or_legacy_authority_cannot_reveal_records_in_any_module(): void
    {
        $legacyPrimary = $this->actor('admin');
        $roleOnly = $this->actor('admin');
        $member = $this->actor('admin');
        $membershipWithoutRole = $this->actor('user');
        $directPermissionOnly = User::factory()->create();
        $permissions = [
            'activities.view', 'announcements.view', 'councils.view',
            'council-members.view', 'council-meetings.view', 'faithful.view',
        ];
        $membershipWithoutRole->givePermissionTo($permissions);
        $directPermissionOnly->givePermissionTo($permissions);
        $mosque = $this->mosque('DENIED');
        $mosque->forceFill(['admin_id' => $legacyPrimary->id])->save();
        $this->membership($member, $mosque, MosqueMembershipType::Member);
        $this->membership($membershipWithoutRole, $mosque, MosqueMembershipType::Administrator);
        $records = $this->records($mosque, $legacyPrimary, 'DENIED');

        foreach ([$legacyPrimary, $roleOnly, $member, $membershipWithoutRole, $directPermissionOnly] as $actor) {
            $this->assertFalse($actor->canAdministerMosque($mosque));
            foreach ($records as $module => $record) {
                $this->actingAs($actor)->getJson(route("admin.{$module}.index"))
                    ->assertOk()
                    ->assertJsonCount(0, 'data');
                $this->actingAs($actor)->getJson(route("admin.{$module}.show", $record))->assertForbidden();
            }
        }
    }

    public function test_suspended_and_archived_administrators_are_removed_from_the_canonical_scope(): void
    {
        $mosque = $this->mosque('INACTIVE');

        foreach ([User::factory()->suspended()->create(), User::factory()->archived()->create()] as $admin) {
            $admin->assignRole('admin');
            $this->membership($admin, $mosque, MosqueMembershipType::Administrator);

            $this->assertFalse($admin->canAdministerMosque($mosque));
            $this->assertFalse(Mosque::query()->administrableBy($admin)->whereKey($mosque)->exists());
            $this->actingAs($admin)->get(route('admin.activities.index'))->assertRedirect(route('login'));
        }
    }

    public function test_all_module_list_scopes_filter_in_sql_without_per_record_authorization_queries(): void
    {
        $admin = $this->actor('admin');
        $localMosque = $this->mosque('VISIBLE');
        $this->membership($admin, $localMosque, MosqueMembershipType::Administrator);
        $local = $this->records($localMosque, $admin, 'VISIBLE');
        foreach (range(1, 12) as $sequence) {
            $this->records($this->mosque('OUTSIDE-'.$sequence), $admin, 'OUTSIDE-'.$sequence);
        }
        $admin->load('roles');

        $queries = [
            'activities' => fn () => Activity::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            'announcements' => fn () => Announcement::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            'councils' => fn () => MosqueCouncil::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            'council-members' => fn () => CouncilMember::query()->whereHas('council.mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            'council-meetings' => fn () => CouncilMeeting::query()->whereHas('council.mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            'faithful' => fn () => Faithful::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
        ];

        foreach ($queries as $module => $query) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $ids = $query();
            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame([$local[$module]->getKey()], $ids->all());
            $this->assertSame(1, $queryCount);
        }
    }

    public function test_announcement_administrator_recipients_are_canonical_active_and_unique(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $dualSource = $this->actor('admin');
        $legacy = $this->actor('admin');
        $member = $this->actor('admin');
        $roleOnly = $this->actor('admin');
        $suspended = User::factory()->suspended()->create();
        $suspended->assignRole('admin');
        $mosque = $this->mosque('RECIPIENTS', $primary);
        $this->membership($secondary, $mosque, MosqueMembershipType::Administrator);
        $this->membership($dualSource, $mosque, MosqueMembershipType::Administrator);
        $this->membership($member, $mosque, MosqueMembershipType::Member);
        $this->membership($suspended, $mosque, MosqueMembershipType::Administrator);
        $this->faithful($mosque, $dualSource, 'DUAL');
        $mosque->forceFill(['admin_id' => $legacy->id])->save();
        $announcement = $this->announcement($mosque, $primary, 'RECIPIENTS');

        $this->actingAs($secondary)->postJson(route('admin.announcements.publish', $announcement))
            ->assertOk()
            ->assertJsonPath('receipts_count', 3);

        $recipientIds = $announcement->receipts()->orderBy('user_id')->pluck('user_id')->all();
        $expectedIds = collect([$primary->id, $secondary->id, $dualSource->id])->sort()->values()->all();
        $this->assertSame($expectedIds, $recipientIds);
        $this->assertNotContains($legacy->id, $recipientIds);
        $this->assertNotContains($member->id, $recipientIds);
        $this->assertNotContains($roleOnly->id, $recipientIds);
        $this->assertNotContains($suspended->id, $recipientIds);
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
        if ($primary !== null && $primary->hasRole('admin')) {
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

    private function records(Mosque $mosque, User $creator, string $suffix): array
    {
        $council = MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Council '.$suffix,
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-01-01',
            'status' => 'inactive',
            'created_by' => $creator->id,
        ]);
        $member = CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => User::factory()->create()->id,
            'function' => 'imam',
            'started_at' => '2026-01-01',
            'status' => 'inactive',
            'created_by' => $creator->id,
        ]);

        return [
            'activities' => Activity::query()->create([
                'mosque_id' => $mosque->id, 'title' => 'Activity '.$suffix, 'type' => 'course',
                'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
                'status' => 'draft', 'created_by' => $creator->id,
            ]),
            'announcements' => $this->announcement($mosque, $creator, $suffix),
            'councils' => $council,
            'council-members' => $member,
            'council-meetings' => CouncilMeeting::query()->create([
                'mosque_council_id' => $council->id, 'title' => 'Meeting '.$suffix,
                'agenda' => 'Agenda', 'scheduled_at' => now()->addDay(), 'status' => 'draft',
                'created_by' => $creator->id,
            ]),
            'faithful' => $this->faithful($mosque, null, $suffix),
        ];
    }

    private function announcement(Mosque $mosque, User $creator, string $suffix): Announcement
    {
        return Announcement::query()->create([
            'mosque_id' => $mosque->id,
            'title' => 'Announcement '.$suffix,
            'body' => 'Body',
            'audience' => 'administrators',
            'status' => 'draft',
            'created_by' => $creator->id,
        ]);
    }

    private function faithful(Mosque $mosque, ?User $user, string $suffix): Faithful
    {
        return Faithful::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $user?->id,
            'registration_number' => 'FID-'.$suffix,
            'first_name' => 'Faithful',
            'last_name' => $suffix,
            'joined_at' => '2026-01-01',
            'status' => 'active',
            'consent_at' => now(),
        ]);
    }
}
