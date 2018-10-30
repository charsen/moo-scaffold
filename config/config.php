<?php

return [
    'database' => [
        /*
         * schema folder
         */
        'schema'  => '/scaffold/database',
        /*
         * storage folder
         */
        'storage' => '/scaffold/storage/database',
    ],

    'api'      => [
        /*
         * schema folder
         */
        'schema'  => '/scaffold/api',
        /*
         * storage folder
         */
        'storage' => '/scaffold/storage/api',
    ],

    /* -----------------------------------------------------------------
    |  Route settings
    | -----------------------------------------------------------------
     */
    'route'    => [
        'enabled'    => true,
        'prefix'     => 'scaffold',
        'middleware' => env('LARAVEL_SCAFFOLD_MIDDLEWARE') ? explode(',', env('LARAVEL_SCAFFOLD_MIDDLEWARE')) : null,
    ],
];
