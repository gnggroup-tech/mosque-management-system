<?php

namespace Tests\Feature\Mosque;

use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MosqueManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_can_create_and_assign_a_mosque(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($superadmin)->postJson(route('admin.mosques.store'), [
            'code' => 'CKY-KAL-001', 'name' => 'Grande Mosquée de Kaloum',
            'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Kaloum',
            'status' => 'active', 'infrastructures' => ['Salle de prière', 'Bibliothèque'],
            'admin_id' => $admin->id,
        ])->assertCreated()->assertJsonPath('admin_id', $admin->id);

        $this->assertDatabaseHas('mosques', ['code' => 'CKY-KAL-001', 'admin_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'mosque.created']);
    }

    public function test_admin_can_only_list_assigned_mosques(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $other->assignRole('admin');
        Mosque::query()->create($this->mosqueData('MOS-001', $admin->id));
        Mosque::query()->create($this->mosqueData('MOS-002', $other->id));

        $this->actingAs($admin)->getJson(route('admin.mosques.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MOS-001');
    }

    public function test_admin_cannot_view_or_update_another_administrators_mosque(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $other->assignRole('admin');
        $mosque = Mosque::query()->create($this->mosqueData('MOS-003', $other->id));

        $this->actingAs($admin)->getJson(route('admin.mosques.show', $mosque))->assertForbidden();
        $this->actingAs($admin)->patchJson(route('admin.mosques.update', $mosque), ['name' => 'Interdit'])->assertForbidden();
    }

    public function test_admin_created_mosque_is_automatically_assigned_to_that_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->postJson(route('admin.mosques.store'), [
            'code' => 'MOS-004', 'name' => 'Mosquée locale',
            'region' => 'Kindia', 'prefecture' => 'Kindia', 'commune' => 'Kindia',
        ])->assertCreated()->assertJsonPath('admin_id', $admin->id);
    }

    public function test_user_can_list_but_cannot_create_or_update_mosques(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->getJson(route('admin.mosques.index'))->assertOk();
        $this->actingAs($user)->postJson(route('admin.mosques.store'), [])->assertForbidden();
    }

    public function test_only_superadmin_can_delete_a_mosque(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');
        $mosque = Mosque::query()->create($this->mosqueData('MOS-005', $admin->id));

        $this->actingAs($admin)->deleteJson(route('admin.mosques.destroy', $mosque))->assertForbidden();
        $this->actingAs($superadmin)->deleteJson(route('admin.mosques.destroy', $mosque))->assertNoContent();
        $this->assertSoftDeleted($mosque);
        $this->assertTrue(AuditLog::query()->where('event', 'mosque.deleted')->exists());
    }

    public function test_validation_rejects_duplicate_codes_and_invalid_coordinates(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');
        Mosque::query()->create($this->mosqueData('MOS-006', null));

        $this->actingAs($superadmin)->postJson(route('admin.mosques.store'), [
            'code' => 'MOS-006', 'name' => 'Doublon', 'region' => 'Conakry',
            'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'latitude' => 95,
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'latitude']);
    }

    public function test_guest_cannot_access_mosques(): void
    {
        $this->getJson(route('admin.mosques.index'))->assertUnauthorized();
    }

    private function mosqueData(string $code, ?int $adminId): array
    {
        return ['code' => $code, 'name' => 'Mosquée '.$code, 'region' => 'Conakry',
            'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $adminId];
    }
}
