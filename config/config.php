<?php

/**
 * Laravel Scaffold Config
 *
 * - 配置中所有的路由，都是相对于 base_path() （必须在base_path()路径下）
 */
return [
    /**
     *  当前编码作者信息
     */
    'author'     => env('LARAVEL_SCAFFOLD_AUTHOR', ''),

    /**
     * 数据库相关文件的路径
     */
    'database'   => [
        'schema'  => 'scaffold/database/',
        'storage' => 'scaffold/storage/database/',
    ],

    /**
     * api 相关文件的路径
     */
    'api'        => [
        'schema'  => 'scaffold/api/',
        'storage' => 'scaffold/storage/api/',
    ],

    /**
     * Eloquent ORM 的路径
     */
    'model'      => [
        'path' => 'app/Entities/',
    ],

    /**
     * Repository 的路径
     */
    'repository' => [
        'path' => 'app/Repositories/',
    ],

    /**
     * 路由设置
     */
    'route'      => [
        'enabled'    => true,
        'prefix'     => 'scaffold',
        'middleware' => env('LARAVEL_SCAFFOLD_MIDDLEWARE')
            ? explode(',', env('LARAVEL_SCAFFOLD_MIDDLEWARE'))
            : null,
    ],
    
    'version' => '0.1.2'
];
