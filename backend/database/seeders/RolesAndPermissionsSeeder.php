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
        $permissions = [
            'platform.manage', 'admins.manage',
            'mosques.view', 'mosques.create', 'mosques.update', 'mosques.delete',
            'councils.view', 'councils.create', 'councils.update', 'councils.delete',
            'council-members.view', 'council-members.create', 'council-members.update', 'council-members.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'faithful.view', 'faithful.manage', 'contributions.view', 'contributions.manage',
            'finances.view', 'finances.manage', 'activities.view', 'activities.manage',
            'announcements.view', 'announcements.manage', 'reports.view', 'audit.view', 'profile.manage',
        ];
        foreach ($permissions as $permission) { Permission::findOrCreate($permission, 'web'); }
        $superadmin = Role::findOrCreate('superadmin', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $user = Role::findOrCreate('user', 'web');
        $superadmin->syncPermissions($permissions);
        $admin->syncPermissions([
            'mosques.view', 'mosques.create', 'mosques.update',
            'councils.view', 'councils.create', 'councils.update', 'councils.delete',
            'council-members.view', 'council-members.create', 'council-members.update', 'council-members.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'faithful.view', 'faithful.manage', 'contributions.view', 'contributions.manage',
            'finances.view', 'finances.manage', 'activities.view', 'activities.manage',
            'announcements.view', 'announcements.manage', 'reports.view', 'profile.manage',
        ]);
        $user->syncPermissions(['mosques.view', 'councils.view', 'council-members.view', 'activities.view', 'announcements.view', 'profile.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
