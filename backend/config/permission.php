<?php

use App\Models\Permission;
use App\Models\Role;

return [
    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],
    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],
    'teams' => false,
    'register_permission_check_method' => true,
    'events' => false,
    'cache' => [
        'expiration_time' => 86400,
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
