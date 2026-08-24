<?php

namespace Tests\Feature\Mosque;

use App\Enums\MosqueMembershipType;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CanonicalMosqueAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_keeps_global_scope_and_can_create_a_mosque(): void
    {
        $superadmin = $this->actor('superadmin');
        $this->mosque('GLOBAL-A');
        $this->mosque('GLOBAL-B');

        $this->actingAs($superadmin)->getJson(route('admin.mosques.index'))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($superadmin)->postJson(route('admin.mosques.store'), $this->payload('GLOBAL-C'))
            ->assertCreated();
    }

    public function test_primary_and_secondary_canonical_administrators_have_the_same_local_scope(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $mosque = $this->mosque('SHARED', $primary);
        $this->membership($secondary, $mosque, MosqueMembershipType::Administrator);

        $this->actingAs($primary)->getJson(route('admin.mosques.show', $mosque))->assertOk();
        $this->actingAs($secondary)->getJson(route('admin.mosques.show', $mosque))->assertOk();
        $this->actingAs($secondary)->patchJson(route('admin.mosques.update', $mosque), [
            'name' => 'Updated by secondary',
            'admin_id' => $secondary->id,
        ])->assertOk()->assertJsonPath('name', 'Updated by secondary');

        $this->assertSame($primary->id, $mosque->fresh()->admin_id);
    }

    public function test_admin_id_without_canonical_membership_and_knowledge_of_id_are_insufficient(): void
    {
        $historicalPrimary = $this->actor('admin');
        $otherAdmin = $this->actor('admin');
        $mosque = $this->mosque('LEGACY-ONLY');
        $mosque->forceFill(['admin_id' => $historicalPrimary->id])->save();

        $this->actingAs($historicalPrimary)->getJson(route('admin.mosques.show', $mosque))->assertForbidden();
        $this->actingAs($otherAdmin)->getJson(route('admin.mosques.show', $mosque))->assertForbidden();
        $this->actingAs($otherAdmin)->patchJson(route('admin.mosques.update', $mosque), ['name' => 'Leaked'])
            ->assertForbidden();
        $this->assertNotSame('Leaked', $mosque->fresh()->name);
    }

    public function test_member_role_only_membership_only_and_direct_permissions_grant_no_scope(): void
    {
        $mosque = $this->mosque('DENIED');
        $memberAdmin = $this->actor('admin');
        $this->membership($memberAdmin, $mosque, MosqueMembershipType::Member);
        $roleOnly = $this->actor('admin');
        $membershipOnly = $this->actor('user');
        $membershipOnly->givePermissionTo(['mosques.view', 'mosques.update']);
        $this->membership($membershipOnly, $mosque, MosqueMembershipType::Administrator);
        $directOnly = $this->actor('user');
        $directOnly->givePermissionTo(['mosques.view', 'mosques.update']);

        foreach ([$memberAdmin, $roleOnly, $membershipOnly, $directOnly] as $actor) {
            $this->assertFalse($actor->canAdministerMosque($mosque));
            $this->actingAs($actor)->getJson(route('admin.mosques.show', $mosque))->assertForbidden();
        }
    }

    public function test_inactive_administrator_is_blocked_even_with_role_permission_and_membership(): void
    {
        $admin = User::factory()->suspended()->create();
        $admin->assignRole('admin');
        $mosque = $this->mosque('INACTIVE');
        $this->membership($admin, $mosque, MosqueMembershipType::Administrator);

        $this->assertFalse($admin->canAdministerMosque($mosque));
        $this->assertFalse(Mosque::query()->administrableBy($admin)->whereKey($mosque)->exists());
        $this->actingAs($admin)->get(route('admin.mosques.show', $mosque))->assertRedirect(route('login'));
    }

    public function test_list_is_limited_in_sql_to_canonical_administrator_memberships_without_n_plus_one(): void
    {
        $admin = $this->actor('admin');
        $authorized = $this->mosque('VISIBLE');
        $memberOnly = $this->mosque('MEMBER-ONLY');
        $outside = $this->mosque('OUTSIDE');
        $this->membership($admin, $authorized, MosqueMembershipType::Administrator);
        $this->membership($admin, $memberOnly, MosqueMembershipType::Member);
        foreach (range(1, 12) as $sequence) {
            $this->mosque('OUTSIDE-'.$sequence);
        }
        $admin->load('roles');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ids = Mosque::query()->administrableBy($admin)->pluck('id');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$authorized->id], $ids->all());
        $this->assertSame(1, $queryCount);
        $this->actingAs($admin)->getJson(route('admin.mosques.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $authorized->id)
            ->assertJsonMissing(['id' => $memberOnly->id])
            ->assertJsonMissing(['id' => $outside->id]);
    }

    public function test_local_admin_cannot_create_even_with_a_direct_create_permission(): void
    {
        $admin = $this->actor('admin');
        $admin->givePermissionTo('mosques.create');

        $this->actingAs($admin)->postJson(route('admin.mosques.store'), $this->payload('SELF-SCOPE'))
            ->assertForbidden();
        $this->assertDatabaseMissing('mosques', ['code' => 'SELF-SCOPE']);
    }

    public function test_permission_seeding_keeps_45_permissions_and_removes_only_create_from_admin(): void
    {
        $adminPermissions = Role::findByName('admin')->permissions->pluck('name');

        $this->assertSame(45, Permission::query()->count());
        $this->assertFalse($adminPermissions->contains('mosques.create'));
        $this->assertTrue($adminPermissions->contains('mosques.view'));
        $this->assertTrue($adminPermissions->contains('mosques.update'));
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('mosques.create'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(45, Permission::query()->count());
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('mosques.create'));
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code, ?User $primary = null): Mosque
    {
        $mosque = Mosque::query()->create($this->payload($code) + ['admin_id' => $primary?->id]);
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

    private function payload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Mosque '.$code,
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
        ];
    }
}
