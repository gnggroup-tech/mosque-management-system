<?php

namespace Tests\Feature\Activity;

use App\Enums\MosqueMembershipType;
use App\Models\Activity;
use App\Models\Mosque;
use App\Models\MosqueMembership;
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
        $mosque = Mosque::query()->create(['code' => fake()->unique()->bothify('MSQ-####'), 'name' => fake()->company(), 'address' => 'Conakry', 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin->id]);
        MosqueMembership::query()->create(['mosque_id' => $mosque->id, 'user_id' => $admin->id, 'membership_type' => MosqueMembershipType::Administrator]);

        return $mosque;
    }

    private function payload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'title' => 'Cours de Coran', 'type' => 'course', 'starts_at' => now()->addDays(2)->toDateTimeString(), 'ends_at' => now()->addDays(2)->addHours(2)->toDateTimeString(), 'capacity' => 2, 'registration_required' => true];
    }

    private function publishedActivity(User $admin, int $capacity = 1, ?Mosque $mosque = null): Activity
    {
        $data = $this->payload($mosque ?? $this->mosque($admin));
        $data['capacity'] = $capacity;
        $data['created_by'] = $admin->id;
        $data['status'] = 'published';
        $data['published_at'] = now();

        return Activity::query()->create($data);
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

    public function test_registration_is_accepted_when_one_place_remains(): void
    {
        $admin = $this->user('admin');
        $activity = $this->publishedActivity($admin, 1);
        $user = $this->user('user');

        $this->actingAs($user)->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    public function test_duplicate_registration_is_rejected(): void
    {
        $admin = $this->user('admin');
        $activity = $this->publishedActivity($admin, 2);
        $user = $this->user('user');

        $this->actingAs($user)->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->actingAs($user)->postJson(route('admin.activities.register', $activity))->assertUnprocessable();
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    public function test_registration_prevents_duplicates_and_capacity_overflow(): void
    {
        $admin = $this->user('admin');
        $activity = $this->publishedActivity($admin, 1);

        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $activity))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cette activité est complète.');
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    public function test_unregistration_removes_the_registration_and_frees_capacity(): void
    {
        $admin = $this->user('admin');
        $activity = $this->publishedActivity($admin, 1);
        $first = $this->user('user');

        $this->actingAs($first)->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->actingAs($first)->deleteJson(route('admin.activities.unregister', $activity))->assertNoContent();
        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $activity))->assertCreated();
        $this->assertDatabaseCount('activity_registrations', 1);
    }

    public function test_activity_capacity_is_isolated_between_mosques(): void
    {
        $firstAdmin = $this->user('admin');
        $secondAdmin = $this->user('admin');
        $fullActivity = $this->publishedActivity($firstAdmin, 1);
        $availableActivity = $this->publishedActivity($secondAdmin, 1);

        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $fullActivity))->assertCreated();
        $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $availableActivity))->assertCreated();

        $this->assertSame(1, $fullActivity->registrations()->count());
        $this->assertSame(1, $availableActivity->registrations()->count());
    }

    public function test_full_activity_message_is_localized_in_supported_languages(): void
    {
        $messages = [
            'fr' => 'Cette activité est complète.',
            'en' => 'The activity is full.',
            'ar' => 'اكتمل عدد المسجلين في النشاط.',
        ];

        foreach ($messages as $locale => $message) {
            $admin = $this->user('admin');
            $activity = $this->publishedActivity($admin, 1);
            $this->actingAs($this->user('user'))->postJson(route('admin.activities.register', $activity))->assertCreated();
            $requester = $this->user('user');
            $requester->update(['locale' => $locale]);

            $this->actingAs($requester)->postJson(route('admin.activities.register', $activity))
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
        }
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
