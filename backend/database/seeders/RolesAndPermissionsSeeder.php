<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = config('permissions.all');
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (config('permissions.roles') as $roleName => $rolePermissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($rolePermissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
