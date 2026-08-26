<?php

namespace Tests\Feature;

use App\Enums\MosqueMembershipType;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\CouncilMeeting;
use App\Models\CouncilMember;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendCommunityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_navigation_is_permission_aware_for_superadmin_admin_and_user(): void
    {
        foreach (['superadmin', 'admin', 'user'] as $role) {
            $actor = $this->actor($role);
            $response = $this->actingAs($actor)->get(route('dashboard'))->assertOk();
            foreach (['admin.faithful.index', 'admin.councils.index', 'admin.activities.index', 'admin.announcements.index'] as $route) {
                $response->assertSee(route($route), false);
            }
            if ($role !== 'superadmin') {
                $response->assertDontSee(route('admin.accounts.index'), false);
            }
        }
    }

    public function test_local_admin_html_lists_are_isolated_to_canonical_mosques(): void
    {
        $admin = $this->actor('admin');
        $visibleMosque = $this->mosque('VISIBLE-COMMUNITY');
        $outsideMosque = $this->mosque('OUTSIDE-COMMUNITY');
        $this->membership($admin, $visibleMosque);

        $visibleFaithful = $this->faithful($visibleMosque, 'VISIBLE-F');
        $outsideFaithful = $this->faithful($outsideMosque, 'OUTSIDE-F');
        $visibleCouncil = $this->council($visibleMosque, 'Visible council');
        $outsideCouncil = $this->council($outsideMosque, 'Outside council');
        $visibleActivity = $this->activity($visibleMosque, $admin, 'Visible activity');
        $outsideActivity = $this->activity($outsideMosque, $admin, 'Outside activity');

        $this->actingAs($admin)->get(route('admin.faithful.index'))->assertSee($visibleFaithful->registration_number)->assertDontSee($outsideFaithful->registration_number);
        $this->actingAs($admin)->get(route('admin.councils.index'))->assertSee($visibleCouncil->name)->assertDontSee($outsideCouncil->name);
        $this->actingAs($admin)->get(route('admin.activities.index'))->assertSee($visibleActivity->title)->assertDontSee($outsideActivity->title);
    }

    public function test_secondary_canonical_admin_can_open_management_forms_but_outside_admin_is_refused(): void
    {
        $secondary = $this->actor('admin');
        $outside = $this->actor('admin');
        $mosque = $this->mosque('SECONDARY');
        $this->membership($secondary, $mosque);
        $faithful = $this->faithful($mosque, 'SECONDARY-F');

        $this->actingAs($secondary)->get(route('admin.faithful.edit', $faithful))->assertOk();
        $this->actingAs($secondary)->get(route('admin.activities.create'))->assertOk()->assertSee($mosque->name);
        $this->actingAs($secondary)->get(route('admin.announcements.create'))->assertOk()->assertSee($mosque->name);
        $this->actingAs($outside)->get(route('admin.faithful.edit', $faithful))->assertForbidden();
    }

    public function test_user_sees_only_personal_faithful_record_without_list_pii_leak(): void
    {
        $user = $this->actor('user');
        $other = $this->actor('user');
        $mosque = $this->mosque('PERSONAL');
        $own = $this->faithful($mosque, 'OWN-F', $user, ['email' => 'own-private@example.test', 'phone' => '+224600000001']);
        $foreign = $this->faithful($mosque, 'FOREIGN-F', $other, ['email' => 'foreign-private@example.test']);

        $this->actingAs($user)->get(route('admin.faithful.index'))
            ->assertOk()->assertSee($own->registration_number)->assertDontSee($foreign->registration_number)
            ->assertDontSee('own-private@example.test')->assertDontSee('foreign-private@example.test');
        $this->actingAs($user)->get(route('admin.faithful.show', $own))->assertOk()->assertSee('own-private@example.test');
        $this->actingAs($user)->get(route('admin.faithful.show', $foreign))->assertForbidden();
    }

    public function test_council_views_keep_religious_function_distinct_and_show_meeting_workflow(): void
    {
        $superadmin = $this->actor('superadmin');
        $memberUser = $this->actor('user');
        $mosque = $this->mosque('COUNCIL-UI');
        $council = $this->council($mosque, 'Council UI');
        $member = CouncilMember::query()->create([
            'mosque_council_id' => $council->id, 'user_id' => $memberUser->id, 'function' => 'imam',
            'started_at' => today(), 'status' => 'active', 'created_by' => $superadmin->id,
        ]);
        $meeting = CouncilMeeting::query()->create([
            'mosque_council_id' => $council->id, 'title' => 'Quorum meeting', 'agenda' => 'Agenda',
            'scheduled_at' => now()->addDay(), 'quorum_required' => 1, 'status' => 'draft', 'created_by' => $superadmin->id,
        ]);
        $meeting->participants()->create(['council_member_id' => $member->id]);

        $this->actingAs($superadmin)->get(route('admin.council-members.show', $member))
            ->assertOk()->assertSee(__('imam'))->assertSee(__('This function is distinct from the application role.'));
        $this->actingAs($superadmin)->get(route('admin.council-meetings.show', $meeting))
            ->assertOk()->assertSee(__('Quorum required'))->assertSee(__('Queue notices'))->assertSee(__('Save attendance'));
    }

    public function test_activity_view_exposes_capacity_and_real_existing_actions(): void
    {
        $superadmin = $this->actor('superadmin');
        $user = $this->actor('user');
        $mosque = $this->mosque('ACTIVITY-UI');
        $activity = $this->activity($mosque, $superadmin, 'Capacity activity', ['capacity' => 2, 'registration_required' => true]);

        $this->actingAs($user)->get(route('admin.activities.show', $activity))
            ->assertOk()->assertSee(__('Register'))->assertSee(__('2 places remaining'));
        $this->actingAs($superadmin)->get(route('admin.activities.show', $activity))
            ->assertOk()->assertSee(__('Cancel activity'))->assertSee(__('Notification processing'));
    }

    public function test_announcement_receipt_uses_available_and_read_states_not_delivery_claims(): void
    {
        $user = $this->actor('user');
        $announcement = Announcement::query()->create([
            'title' => 'Internal announcement', 'body' => 'Internal body', 'type' => 'official',
            'priority' => 'normal', 'audience' => 'all', 'status' => 'published', 'published_at' => now(), 'created_by' => $user->id,
        ]);
        AnnouncementReceipt::query()->create([
            'announcement_id' => $announcement->id, 'user_id' => $user->id, 'available_at' => now(), 'delivered_at' => now(),
        ]);

        $this->actingAs($user)->get(route('admin.announcements.index'))->assertOk()->assertSee(__('Unread'));
        $this->actingAs($user)->get(route('admin.announcements.show', $announcement))
            ->assertOk()->assertSee(__('Available internally'))->assertSee(__('Internal availability is not proof of external delivery or reading.'))
            ->assertDontSee('delivered_at');
        $this->actingAs($user)->post(route('admin.announcements.read', $announcement))->assertRedirect(route('admin.announcements.show', $announcement));
        $this->assertNotNull(AnnouncementReceipt::query()->firstOrFail()->read_at);
    }

    public function test_existing_json_contracts_remain_json_and_arabic_views_are_rtl(): void
    {
        $superadmin = $this->actor('superadmin');
        $mosque = $this->mosque('JSON-UI');
        $faithful = $this->faithful($mosque, 'JSON-F');
        $activity = $this->activity($mosque, $superadmin, 'JSON activity');

        $this->actingAs($superadmin)->getJson(route('admin.faithful.show', $faithful))->assertOk()->assertJsonPath('registration_number', 'JSON-F');
        $this->actingAs($superadmin)->getJson(route('admin.activities.show', $activity))->assertOk()->assertJsonPath('title', 'JSON activity')->assertJsonMissingPath('notification_deliveries');
        $this->actingAs($superadmin)->withSession(['locale' => 'ar'])->get(route('admin.activities.index'))->assertOk()->assertSee('dir="rtl"', false);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code): Mosque
    {
        return Mosque::query()->create(['code' => $code, 'name' => 'Mosque '.$code, 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active']);
    }

    private function membership(User $user, Mosque $mosque): void
    {
        MosqueMembership::query()->create(['user_id' => $user->id, 'mosque_id' => $mosque->id, 'membership_type' => MosqueMembershipType::Administrator]);
    }

    private function faithful(Mosque $mosque, string $number, ?User $user = null, array $extra = []): Faithful
    {
        return Faithful::query()->create(array_merge([
            'mosque_id' => $mosque->id, 'user_id' => $user?->id, 'registration_number' => $number,
            'first_name' => 'First', 'last_name' => $number, 'joined_at' => today(), 'status' => 'active', 'consent_at' => now(),
        ], $extra));
    }

    private function council(Mosque $mosque, string $name): MosqueCouncil
    {
        return MosqueCouncil::query()->create(['mosque_id' => $mosque->id, 'name' => $name, 'mandate_start' => today(), 'mandate_end' => today()->addYear(), 'status' => 'active']);
    }

    private function activity(Mosque $mosque, User $creator, string $title, array $extra = []): Activity
    {
        return Activity::query()->create(array_merge([
            'mosque_id' => $mosque->id, 'title' => $title, 'type' => 'course', 'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(), 'status' => 'published', 'published_at' => now(), 'created_by' => $creator->id,
        ], $extra));
    }
}
