<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Roles
    |--------------------------------------------------------------------------
    |
    | Define the hardcoded system roles that will be created during deployment.
    | - `level`: Determines hierarchy (higher number = more privileges).
    | - `is_system`: If true, the role cannot be deleted by users.
    |
    */

    'roles' => [
        'admin' => [
            'level' => 100,
            'is_system' => true,
        ],
        'manager' => [
            'level' => 50,
            'is_system' => true,
        ],
        'teller' => [
            'level' => 10,
            'is_system' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Permissions
    |--------------------------------------------------------------------------
    |
    | Define all permissions available in the system.
    | They will be mapped to the default system roles during seeding.
    |
    */

    'permissions' => [
        // Role & User Management
        'manage_users' => 'Create, edit, and delete users',
        'manage_roles' => 'Create, edit, and delete custom roles',
        
        // Settings & Configuration
        'configure_system' => 'Modify core system settings and billing',
        'configure_gateways' => 'Configure M-Pesa, SMS, and Email API keys',
        
        // Branch Management
        'manage_branches' => 'Create, edit, and delete branches',
        
        // Transactions & Operations
        'view_transactions' => 'View transaction history',
        'create_transactions' => 'Process new sales and transactions',
        'void_transactions' => 'Void or refund transactions (requires override)',
        
        // Reporting
        'view_reports' => 'View analytical reports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role Assignments
    |--------------------------------------------------------------------------
    |
    | Map which permissions belong to which system roles by default.
    |
    */

    'assignments' => [
        'admin' => [
            'manage_users',
            'manage_roles',
            'configure_system',
            'configure_gateways',
            'manage_branches',
            'view_transactions',
            'create_transactions',
            'void_transactions',
            'view_reports',
        ],
        'manager' => [
            'manage_users', // Bounded by their branch and level
            'view_transactions',
            'create_transactions',
            'void_transactions',
            'view_reports',
        ],
        'teller' => [
            'view_transactions',
            'create_transactions',
        ],
    ],

];
