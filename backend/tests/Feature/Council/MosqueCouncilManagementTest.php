<?php

namespace Tests\Feature\Council;

use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MosqueCouncilManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_can_create_a_council_for_any_mosque(): void
    {
        $superadmin = $this->userWithRole('superadmin');
        $mosque = $this->mosqueFor(null);

        $this->actingAs($superadmin)->postJson(route('admin.councils.store'), [
            'mosque_id' => $mosque->id,
            'name' => 'Conseil 2026-2030',
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-12-31',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('mosque_id', $mosque->id)
            ->assertJsonPath('created_by', $superadmin->id);

        $this->assertDatabaseHas('mosque_councils', ['mosque_id' => $mosque->id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'council.created']);
    }

    public function test_admin_can_manage_only_councils_of_assigned_mosques(): void
    {
        $admin = $this->userWithRole('admin');
        $otherAdmin = $this->userWithRole('admin');
        $assignedMosque = $this->mosqueFor($admin);
        $otherMosque = $this->mosqueFor($otherAdmin);
        $otherCouncil = $this->councilFor($otherMosque, 'inactive');

        $this->actingAs($admin)->postJson(route('admin.councils.store'), [
            'mosque_id' => $assignedMosque->id,
            'name' => 'Conseil local',
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-01-01',
        ])->assertCreated();

        $this->actingAs($admin)
            ->patchJson(route('admin.councils.update', $otherCouncil), ['name' => 'Interdit'])
            ->assertForbidden();
    }

    public function test_admin_list_is_limited_to_assigned_mosques(): void
    {
        $admin = $this->userWithRole('admin');
        $otherAdmin = $this->userWithRole('admin');
        $own = $this->councilFor($this->mosqueFor($admin), 'active');
        $this->councilFor($this->mosqueFor($otherAdmin), 'active');

        $this->actingAs($admin)->getJson(route('admin.councils.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_a_mosque_cannot_have_two_active_councils(): void
    {
        $superadmin = $this->userWithRole('superadmin');
        $mosque = $this->mosqueFor(null);
        $this->councilFor($mosque, 'active');

        $this->actingAs($superadmin)->postJson(route('admin.councils.store'), [
            'mosque_id' => $mosque->id,
            'name' => 'Deuxième conseil actif',
            'mandate_start' => '2027-01-01',
            'mandate_end' => '2031-01-01',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_an_inactive_council_can_be_activated_after_the_previous_is_closed(): void
    {
        $superadmin = $this->userWithRole('superadmin');
        $mosque = $this->mosqueFor(null);
        $current = $this->councilFor($mosque, 'active');
        $next = $this->councilFor($mosque, 'inactive');

        $this->actingAs($superadmin)
            ->patchJson(route('admin.councils.update', $current), ['status' => 'expired'])
            ->assertOk();

        $this->actingAs($superadmin)
            ->patchJson(route('admin.councils.update', $next), ['status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_mandate_end_must_be_after_start_date(): void
    {
        $superadmin = $this->userWithRole('superadmin');
        $mosque = $this->mosqueFor(null);

        $this->actingAs($superadmin)->postJson(route('admin.councils.store'), [
            'mosque_id' => $mosque->id,
            'name' => 'Mandat invalide',
            'mandate_start' => '2030-01-01',
            'mandate_end' => '2029-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('mandate_end');
    }

    public function test_user_can_view_only_active_councils(): void
    {
        $user = $this->userWithRole('user');
        $active = $this->councilFor($this->mosqueFor(null), 'active');
        $inactive = $this->councilFor($this->mosqueFor(null), 'inactive');

        $this->actingAs($user)->getJson(route('admin.councils.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->actingAs($user)->getJson(route('admin.councils.show', $inactive))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.councils.store'), [])->assertForbidden();
    }

    public function test_authorized_admin_can_soft_delete_a_local_council(): void
    {
        $admin = $this->userWithRole('admin');
        $council = $this->councilFor($this->mosqueFor($admin), 'inactive');

        $this->actingAs($admin)->deleteJson(route('admin.councils.destroy', $council))->assertNoContent();

        $this->assertSoftDeleted($council);
        $this->assertDatabaseHas('audit_logs', ['event' => 'council.deleted']);
    }

    public function test_guest_cannot_access_councils(): void
    {
        $this->getJson(route('admin.councils.index'))->assertUnauthorized();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosqueFor(?User $admin): Mosque
    {
        static $sequence = 0;
        $sequence++;

        return Mosque::query()->create([
            'code' => 'MOS-C'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => 'Mosquée Conseil '.$sequence,
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'admin_id' => $admin?->id,
        ]);
    }

    private function councilFor(Mosque $mosque, string $status): MosqueCouncil
    {
        return MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Conseil '.$mosque->code.' '.$status,
            'mandate_start' => '2026-01-01',
            'mandate_end' => '2030-01-01',
            'status' => $status,
        ]);
    }
}
