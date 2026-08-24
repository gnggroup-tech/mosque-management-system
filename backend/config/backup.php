<?php

$restoreOrder = [
    'users',
    'permissions',
    'roles',
    'model_has_permissions',
    'model_has_roles',
    'role_has_permissions',
    'mosques',
    'mosque_user',
    'user_invitations',
    'mosque_councils',
    'council_members',
    'faithful',
    'donations',
    'zakat_beneficiaries',
    'zakat_collections',
    'zakat_distributions',
    'waqf_assets',
    'waqf_revenues',
    'waqf_expenses',
    'subsidies',
    'expenses',
    'activities',
    'activity_registrations',
    'announcements',
    'announcement_receipts',
    'council_meetings',
    'council_meeting_participants',
    'council_decisions',
    'audit_logs',
];

return [
    'disk' => env('SGAR_BACKUP_DISK', 'backups'),
    'retention_days' => (int) env('SGAR_BACKUP_RETENTION_DAYS', 30),
    'application_version' => env('APP_VERSION'),
    'tables' => $restoreOrder,
    'required_tables' => $restoreOrder,
    'restore' => [
        'allowed_environments' => ['local', 'testing'],
        'require_empty_database' => true,
    ],
    'excluded_columns' => [
        'users' => ['password', 'remember_token'],
    ],
];
