<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_seeder_creates_the_three_global_roles(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'superadmin', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'web']);
        $this->assertDatabaseHas('roles', ['name' => 'user', 'guard_name' => 'web']);
        $this->assertSame(3, Role::count());
    }

    public function test_superadmin_receives_every_defined_permission(): void
    {
        $superadmin = Role::findByName('superadmin');

        $this->assertSame(Permission::count(), $superadmin->permissions()->count());
        $this->assertTrue($superadmin->hasPermissionTo('platform.manage'));
        $this->assertTrue($superadmin->hasPermissionTo('admins.manage'));
        $this->assertTrue($superadmin->hasPermissionTo('users.approve'));
        $this->assertTrue($superadmin->hasPermissionTo('users.directory.view'));
        $this->assertTrue($superadmin->hasPermissionTo('users.invite'));
        $this->assertTrue($superadmin->hasPermissionTo('users.suspend'));
        $this->assertTrue($superadmin->hasPermissionTo('users.reactivate'));
        $this->assertTrue($superadmin->hasPermissionTo('users.archive'));
        $this->assertTrue($superadmin->hasPermissionTo('audit.view'));
    }

    public function test_admin_can_manage_local_resources_but_not_the_platform(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('mosques.update'));
        $this->assertTrue($admin->can('councils.create'));
        $this->assertTrue($admin->can('finances.manage'));
        $this->assertFalse($admin->can('platform.manage'));
        $this->assertFalse($admin->can('admins.manage'));
        $this->assertFalse($admin->can('users.approve'));
        $this->assertFalse($admin->can('users.directory.view'));
        $this->assertFalse($admin->can('users.invite'));
        $this->assertFalse($admin->can('users.suspend'));
        $this->assertFalse($admin->can('users.reactivate'));
        $this->assertFalse($admin->can('users.archive'));
        $this->assertFalse($admin->can('audit.view'));
        $this->assertFalse($admin->can('mosques.delete'));
    }

    public function test_user_has_read_only_access_and_can_manage_own_profile(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->assertTrue($user->can('mosques.view'));
        $this->assertTrue($user->can('councils.view'));
        $this->assertTrue($user->can('activities.view'));
        $this->assertTrue($user->can('announcements.view'));
        $this->assertTrue($user->can('profile.manage'));
        $this->assertFalse($user->can('mosques.update'));
        $this->assertFalse($user->can('finances.manage'));
        $this->assertFalse($user->can('users.approve'));
        $this->assertFalse($user->can('users.directory.view'));
        $this->assertFalse($user->can('users.invite'));
        $this->assertFalse($user->can('users.suspend'));
        $this->assertFalse($user->can('users.reactivate'));
        $this->assertFalse($user->can('users.archive'));
    }

    public function test_seeder_can_be_run_more_than_once_without_duplicates(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(3, Role::count());
        $this->assertSame($this->canonicalPermissions(), Permission::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_seeder_creates_exactly_the_canonical_permissions(): void
    {
        $expected = $this->canonicalPermissions();

        $this->assertCount(43, $expected);
        $this->assertCount(count(array_unique($expected)), $expected);
        $this->assertSame($expected, Permission::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_each_role_receives_exactly_its_configured_permissions(): void
    {
        foreach (config('permissions.roles') as $roleName => $permissions) {
            $actual = Role::findByName($roleName)->permissions->pluck('name')->sort()->values()->all();
            $expected = collect($permissions)->sort()->values()->all();

            $this->assertSame($expected, $actual, "Unexpected permissions for role {$roleName}");
        }
    }

    private function canonicalPermissions(): array
    {
        return collect(config('permissions.all'))->sort()->values()->all();
    }
}
