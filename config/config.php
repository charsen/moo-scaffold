<?php

return [
    'database'   => [
        /**
         * schema path, 在 base_path() 下
         */
        'schema'  => 'scaffold/database/',
        /*
         * storage path, 在 base_path() 下
         */
        'storage' => 'scaffold/storage/database/',
    ],

    'api'        => [
        /**
         * schema path, 在 base_path() 下
         */
        'schema'  => 'scaffold/api/',
        /**
         * storage path, 在 base_path() 下
         */
        'storage' => 'scaffold/storage/api/',
    ],

    'model'      => [
        /**
         *  在 app 目录下的路径 app_path()
         */
        'path' => 'Entities/',
    ],

    'repository' => [
        /**
         *  在 app 下的路径 app_path()
         */
        'path' => 'Repositories/',
    ],

    /* -----------------------------------------------------------------
    |  Route settings
    | -----------------------------------------------------------------
     */
    'route'      => [
        'enabled'    => true,
        'prefix'     => 'scaffold',
        'middleware' => env('LARAVEL_SCAFFOLD_MIDDLEWARE') ? explode(',', env('LARAVEL_SCAFFOLD_MIDDLEWARE')) : null,
    ],
];
