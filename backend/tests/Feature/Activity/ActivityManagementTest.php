<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\Mosque;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityManagementTest extends TestCase
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
        return Mosque::query()->create(['code' => fake()->unique()->bothify('MSQ-####'), 'name' => fake()->company(), 'address' => 'Conakry', 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin->id]);
    }

    private function payload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'title' => 'Cours de Coran', 'type' => 'course', 'starts_at' => now()->addDays(2)->toDateTimeString(), 'ends_at' => now()->addDays(2)->addHours(2)->toDateTimeString(), 'capacity' => 2, 'registration_required' => true];
    }

    public function test_admin_creates_activity_only_for_assigned_mosque(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $this->actingAs($admin)->postJson(route('admin.activities.store'), $this->payload($this->mosque($admin)))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.activities.store'), $this->payload($this->mosque($other)))->assertForbidden();
    }

    public function test_end_must_be_after_start(): void
    {
        $admin = $this->user('admin');
        $payload = $this->payload($this->mosque($admin));
        $payload['ends_at'] = now()->toDateTimeString();
        $this->actingAs($admin)->postJson(route('admin.activities.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_user_sees_only_published_activities(): void
    {
        $admin = $this->user('admin');
        $mosque = $this->mosque($admin);
        Activity::query()->create($this->payload($mosque) + ['created_by' => $admin->id, 'status' => 'draft']);
        $this->actingAs($this->user('user'))->getJson(route('admin.activities.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_activity_can_be_published_once(): void
    {
        $admin = $this->user('admin');
        $activity = Activity::query()->create($this->payload($this->mosque($admin)) + ['created_by' => $admin->id, 'status' => 'draft']);
        $this->actingAs($admin)->postJson(route('admin.activities.publish', $activity))->assertOk()->assertJsonPath('status', 'published');
        $this->actingAs($admin)->postJson(route('admin.activities.publish', $activity->fresh()))->assertUnprocessable();
    }

    public function test_registration_prevents_duplicates_and_capacity_overflow(): void
    {
        $admin = $this->user('admin');
        $activity = Activity::query()->create($this->payload($this->mosque($admin)) + ['created_by' => $admin->id, 'status' => 'published', 'published_at' => now(), 'capacity' => 1]);
        $user = $this->user('user');
        $this->actingAs($user)->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->actingAs($user)->postJson(route('admin.activities.register', $activity))->assertUnprocessable();
        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $activity))->assertUnprocessable();
    }

    public function test_admin_cannot_view_other_mosque_activity(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $activity = Activity::query()->create($this->payload($this->mosque($other)) + ['created_by' => $other->id, 'status' => 'published']);
        $this->actingAs($admin)->getJson(route('admin.activities.show', $activity))->assertForbidden();
    }

    public function test_guest_cannot_access_activities(): void
    {
        $this->getJson(route('admin.activities.index'))->assertUnauthorized();
    }

    public function test_activity_changes_are_audited(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson(route('admin.activities.store'), $this->payload($this->mosque($admin)))->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'activity.created', 'actor_id' => $admin->id]);
    }
}
