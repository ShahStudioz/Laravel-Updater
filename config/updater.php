<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Updater Configuration
    |--------------------------------------------------------------------------
    */

    /*
    | Temporary directory for downloads and processing
    */
    'tmp_directory' => 'updater_tmp',

    /*
    | Script filename called during the update process
    */
    'script_filename' => 'upgrade.php',

    /*
    | Base URL where updates are stored
    */
    'update_baseurl' => env('UPDATER_URL', 'http://your-update-server.com/updates'),

    /*
    | Route prefix for the updater
    */
    'route_prefix' => 'admin/updater',

    /*
    | Middleware for the updater routes
    */
    'middleware' => ['web', 'auth', 'admin'],

    /*
    | Set which users can perform an update
    | Set to false to disable this check (not recommended)
    */
    'allow_users_id' => [1],

    /*
    | Enable/disable online update checks
    */
    'online_check' => true,

    /*
    | Commands to run after update
    */
    'post_update_commands' => [
        'cache:clear',
        'config:cache',
        'view:cache',
        'route:cache',
        'migrate --force'
    ],

    /*
    | Paths to exclude from the update process
    */
    'excluded_paths' => [
        '.env',
        'storage',
        'bootstrap/cache',
    ],
];
