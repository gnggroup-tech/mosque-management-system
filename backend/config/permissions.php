<?php

$all = [
    'platform.manage', 'admins.manage',
    'mosques.view', 'mosques.create', 'mosques.update', 'mosques.delete',
    'councils.view', 'councils.create', 'councils.update', 'councils.delete',
    'council-members.view', 'council-members.create', 'council-members.update', 'council-members.delete',
    'council-meetings.view', 'council-meetings.manage',
    'users.view', 'users.create', 'users.update', 'users.delete', 'users.approve', 'users.directory.view', 'users.invite',
    'users.suspend', 'users.reactivate', 'users.archive',
    'faithful.view', 'faithful.manage', 'contributions.view', 'contributions.manage',
    'zakat.view', 'zakat.manage', 'waqf.view', 'waqf.manage',
    'finances.view', 'finances.manage', 'activities.view', 'activities.manage',
    'announcements.view', 'announcements.manage', 'reports.view', 'audit.view', 'profile.manage',
];

return [
    'all' => $all,
    'roles' => [
        'superadmin' => $all,
        'admin' => [
            'mosques.view', 'mosques.create', 'mosques.update',
            'councils.view', 'councils.create', 'councils.update', 'councils.delete',
            'council-members.view', 'council-members.create', 'council-members.update', 'council-members.delete',
            'council-meetings.view', 'council-meetings.manage',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'faithful.view', 'faithful.manage', 'contributions.view', 'contributions.manage',
            'zakat.view', 'zakat.manage', 'waqf.view', 'waqf.manage',
            'finances.view', 'finances.manage', 'activities.view', 'activities.manage',
            'announcements.view', 'announcements.manage', 'reports.view', 'profile.manage',
        ],
        'user' => [
            'mosques.view', 'councils.view', 'council-members.view', 'council-meetings.view',
            'faithful.view', 'activities.view', 'announcements.view', 'profile.manage',
        ],
    ],
];
