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
        // 是否开启 验证验证
        'check' => TRUE,
        // 在 app_path() 下需要排除的目录，不生成权限验证 actions 的
        'exclude_forder' => ['App'],
        // 是否通过 md5 加密别名 key
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
     * 生成时 资源 及  对应的类，可以自定义、及修改类的位置
     *
     */
    'class' => [
        'resources' => [
            'base'          => 'Charsen\Scaffold\Http\Resources\BaseResource',
            'form'          => 'Charsen\Scaffold\Http\Resources\FormWidgetCollection',
            'colums'        => 'Charsen\Scaffold\Http\Resources\TableColumsCollection'
        ],
        'actions'           => 'Charsen\Scaffold\Foundation\Actions',
        'controller'        => 'Charsen\Scaffold\Foundation\Controller',
        'form_request'      => 'Charsen\Scaffold\Foundation\FormRequest',
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
    ]
];
