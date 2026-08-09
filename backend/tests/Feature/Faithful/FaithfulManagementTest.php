<?php

namespace Tests\Feature\Faithful;

use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaithfulManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_can_register_a_faithful_with_consent(): void
    {
        $superadmin = $this->user('superadmin');
        $mosque = $this->mosque();
        $this->actingAs($superadmin)->postJson(route('admin.faithful.store'), $this->payload($mosque))->assertCreated()->assertJsonPath('registration_number', 'FID-0001');
        $this->assertDatabaseHas('audit_logs', ['event' => 'faithful.created']);
    }

    public function test_consent_is_required(): void
    {
        $superadmin = $this->user('superadmin');
        $payload = $this->payload($this->mosque());
        unset($payload['consent_at']);
        $this->actingAs($superadmin)->postJson(route('admin.faithful.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('consent_at');
    }

    public function test_admin_manages_only_faithful_of_assigned_mosques(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $own = $this->mosque($admin);
        $outside = $this->record($this->mosque($other));
        $this->actingAs($admin)->postJson(route('admin.faithful.store'), $this->payload($own))->assertCreated();
        $this->actingAs($admin)->patchJson(route('admin.faithful.update', $outside), ['status' => 'inactive'])->assertForbidden();
    }

    public function test_user_can_view_only_their_own_faithful_record(): void
    {
        $user = $this->user('user');
        $own = $this->record($this->mosque(), $user);
        $this->record($this->mosque());
        $this->actingAs($user)->getJson(route('admin.faithful.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->actingAs($user)->patchJson(route('admin.faithful.update', $own), ['phone' => '620000001'])->assertForbidden();
    }

    public function test_registration_number_and_linked_user_are_unique(): void
    {
        $superadmin = $this->user('superadmin');
        $user = $this->user('user');
        $existing = $this->record($this->mosque(), $user);
        $payload = $this->payload($this->mosque());
        $payload['user_id'] = $user->id;
        $payload['registration_number'] = $existing->registration_number;
        $this->actingAs($superadmin)->postJson(route('admin.faithful.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['registration_number', 'user_id']);
    }

    public function test_search_and_status_filters_are_available(): void
    {
        $superadmin = $this->user('superadmin');
        $record = $this->record($this->mosque());
        $record->update(['first_name' => 'Mamadou', 'status' => 'active']);
        $this->actingAs($superadmin)->getJson(route('admin.faithful.index', ['search' => 'Mamadou', 'status' => 'active']))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_authorized_admin_can_soft_delete_and_audit_a_record(): void
    {
        $admin = $this->user('admin');
        $record = $this->record($this->mosque($admin));
        $this->actingAs($admin)->deleteJson(route('admin.faithful.destroy', $record))->assertNoContent();
        $this->assertSoftDeleted($record);
        $this->assertDatabaseHas('audit_logs', ['event' => 'faithful.deleted']);
    }

    public function test_guest_cannot_access_faithful_records(): void
    {
        $this->getJson(route('admin.faithful.index'))->assertUnauthorized();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(?User $admin = null): Mosque
    {
        static $n = 0;
        $n++;

        return Mosque::query()->create(['code' => 'MOS-F'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'name' => 'Mosquée '.$n, 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin?->id]);
    }

    private function payload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'registration_number' => 'FID-0001', 'first_name' => 'Ibrahima', 'last_name' => 'Diallo', 'phone' => '620000000', 'joined_at' => '2026-08-01', 'consent_at' => '2026-08-01 10:00:00'];
    }

    private function record(Mosque $mosque, ?User $user = null): Faithful
    {
        static $n = 1;
        $n++;

        return Faithful::query()->create(['mosque_id' => $mosque->id, 'user_id' => $user?->id, 'registration_number' => 'FID-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'first_name' => 'Fidèle', 'last_name' => 'Test', 'joined_at' => '2026-08-01', 'consent_at' => now(), 'status' => 'active']);
    }
}
