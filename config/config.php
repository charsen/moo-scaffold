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
        // 在 app_path() 下需要排除的目录，不生成权限验证 actions 的
        'exclude_forder' => ['App'],
        // 是否在 app_path() 下生成 ACL.php ，用于人工核对数据
        'make_acl'  => FALSE,
        // 是否通过 md5 多语别名 key
        'md5'       => FALSE,
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
     * App 路由文件设定
      */
    'routes' => [
        'prefix' => 'admin',
        'admin'  => 'routes/admin.php',
        'api'    => 'routes/api.php',
    ],

    /**
     * 数据库相关文件的路径
     */
    'database'   => [
        'schema'  => 'scaffold/database/',
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
        'path' => 'app/Models/',
    ],

    /**
     * Scaffold 路由设置
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
