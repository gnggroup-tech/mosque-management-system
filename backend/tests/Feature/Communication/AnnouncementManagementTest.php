<?php

namespace Tests\Feature\Communication;

use App\Enums\MosqueMembershipType;
use App\Models\Announcement;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(User $admin): Mosque
    {
        $mosque = Mosque::query()->create(['code' => fake()->unique()->bothify('MSQ-####'), 'name' => fake()->company(), 'address' => 'Conakry', 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin->id]);
        MosqueMembership::query()->create(['mosque_id' => $mosque->id, 'user_id' => $admin->id, 'membership_type' => MosqueMembershipType::Administrator]);

        return $mosque;
    }

    private function faithful(User $user, Mosque $mosque, User $creator): Faithful
    {
        return Faithful::query()->create(['mosque_id' => $mosque->id, 'user_id' => $user->id, 'registration_number' => fake()->unique()->bothify('FID-####'), 'first_name' => 'Mamadou', 'last_name' => 'Diallo', 'joined_at' => now()->toDateString(), 'status' => 'active', 'consent_at' => now(), 'created_by' => $creator->id]);
    }

    private function payload(?Mosque $mosque): array
    {
        return ['mosque_id' => $mosque?->id, 'title' => 'Réunion du vendredi', 'body' => 'Réunion après la prière.', 'type' => 'meeting', 'priority' => 'important', 'audience' => 'all', 'visible_until' => now()->addDays(3)->toDateTimeString()];
    }

    public function test_only_superadmin_can_create_national_announcement(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson(route('admin.announcements.store'), $this->payload(null))->assertForbidden();
        $this->actingAs($this->user('superadmin'))->postJson(route('admin.announcements.store'), $this->payload(null))->assertCreated();
    }

    public function test_admin_can_create_only_for_assigned_mosque(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $this->actingAs($admin)->postJson(route('admin.announcements.store'), $this->payload($this->mosque($admin)))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.announcements.store'), $this->payload($this->mosque($other)))->assertForbidden();
    }

    public function test_publishing_is_idempotent_and_creates_targeted_receipts(): void
    {
        $admin = $this->user('admin');
        $mosque = $this->mosque($admin);
        $target = $this->user('user');
        $outsider = $this->user('user');
        $this->faithful($target, $mosque, $admin);
        $announcement = Announcement::query()->create($this->payload($mosque) + ['created_by' => $admin->id, 'status' => 'draft']);

        $this->actingAs($admin)->postJson(route('admin.announcements.publish', $announcement))->assertOk()->assertJsonPath('status', 'published');
        $this->assertDatabaseHas('announcement_receipts', ['announcement_id' => $announcement->id, 'user_id' => $target->id, 'read_at' => null]);
        $receipt = $announcement->receipts()->where('user_id', $target->id)->sole();
        $this->assertNotNull($receipt->available_at);
        $this->assertTrue($receipt->available_at->equalTo($receipt->delivered_at));
        $this->assertDatabaseMissing('announcement_receipts', ['announcement_id' => $announcement->id, 'user_id' => $outsider->id]);
        $this->actingAs($admin)->postJson(route('admin.announcements.publish', $announcement->fresh()))
            ->assertOk()
            ->assertJsonPath('receipts_count', 2);
        $this->assertSame(2, $announcement->receipts()->count());
    }

    public function test_faithful_sees_only_delivered_and_current_announcements(): void
    {
        $admin = $this->user('admin');
        $mosque = $this->mosque($admin);
        $faithful = $this->user('user');
        $this->faithful($faithful, $mosque, $admin);
        $current = Announcement::query()->create($this->payload($mosque) + ['created_by' => $admin->id, 'status' => 'draft']);
        $future = Announcement::query()->create(array_merge($this->payload($mosque), ['title' => 'Future', 'visible_from' => now()->addDay(), 'visible_until' => now()->addDays(2), 'created_by' => $admin->id, 'status' => 'draft']));
        $this->actingAs($admin)->postJson(route('admin.announcements.publish', $current))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.announcements.publish', $future))->assertOk();
        $this->actingAs($faithful)->getJson(route('admin.announcements.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', $current->title);
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $admin = $this->user('admin');
        $mosque = $this->mosque($admin);
        $faithful = $this->user('user');
        $this->faithful($faithful, $mosque, $admin);
        $announcement = Announcement::query()->create($this->payload($mosque) + ['created_by' => $admin->id, 'status' => 'draft']);
        $this->actingAs($admin)->postJson(route('admin.announcements.publish', $announcement))->assertOk();
        $this->actingAs($faithful)->postJson(route('admin.announcements.read', $announcement))->assertOk();
        $this->assertDatabaseMissing('announcement_receipts', ['announcement_id' => $announcement->id, 'user_id' => $faithful->id, 'read_at' => null]);
    }

    public function test_admin_cannot_view_other_mosque_announcement(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $announcement = Announcement::query()->create($this->payload($this->mosque($other)) + ['created_by' => $other->id, 'status' => 'draft']);
        $this->actingAs($admin)->getJson(route('admin.announcements.show', $announcement))->assertForbidden();
    }

    public function test_guest_cannot_access_announcements(): void
    {
        $this->getJson(route('admin.announcements.index'))->assertUnauthorized();
    }

    public function test_announcement_changes_are_audited(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson(route('admin.announcements.store'), $this->payload($this->mosque($admin)))->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'announcement.created', 'actor_id' => $admin->id]);
    }
}
