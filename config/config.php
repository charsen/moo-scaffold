<?php

/**
 * Laravel Scaffold Config
 *
 * - 配置中所有的路由，都是相对于 base_path() （必须在base_path()路径下）
 */
return [
    /**
     * 当前编码作者信息
     */
    'author'     => env('LARAVEL_SCAFFOLD_AUTHOR', ''),

    /**
     * 授权设置
     */
    'authorization' => [
        // 是否通过 md5 多语别名 key
        'md5'       => TRUE,
        // 是否用 16位 md5 算法
        'short_md5' => TRUE,
        // todo: 根据不同的目录指定不同的 Auth::guard()
        'guard'     => []
    ],

    /**
     *  多语言设定
     */
    'languages' => ['en', 'zh-CN'],

    /**
     * 数据库相关文件的路径
     */
    'database'   => [
        'schema'  => 'scaffold/database/',
        'storage' => 'scaffold/storage/',
    ],

    /**
     * api 相关文件的路径
     */
    'api'        => [
        'schema'  => 'scaffold/api/',
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
     * todo: 待完成中间件
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
