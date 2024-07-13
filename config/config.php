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
    'author' => env('SCAFFOLD_AUTHOR', ''),

    /**
     * 是否在产品环境生成代码
     */
    'enable_in_prod' => false,

    /**
     * 是否使用雪花 ID 主键算法
     */
    'snow_flake_id' => true,

    /**
     * 后台，授权验证
     */
    'authorization' => [
        // 控制器所以目录
        'folder' => 'app/Admin/Controllers/',
        // 是否开启 验证验证
        'check' => false,
        // 是否通过 md5 加密别名 key
        'md5' => true,
        // 排除制定的动作，生成 api 时，header 中不包含 Authorization 参数
        'exclude_actions' => [
            'App\Admin\Controllers\AuthController@login',
            'App\Admin\Controllers\AuthController@authenticate',
        ],
    ],

    /**
     *  多语言设定
     */
    'languages' => ['en', 'zh-CN'],

    /**
     * 数据库相关文件的路径
     */
    'database' => [
        'schema' => 'scaffold/database/',
    ],

    /**
     * Eloquent ORM 的路径
     */
    'model' => [
        'path' => 'app/Models/',
    ],

    /**
     * Controller 配置
     */
    'controller' => [
        'admin' => [
            'name'       => ['zh-CN' => '后台管理', 'en' => 'Admin'],
            'path'       => 'app/Admin/Controllers/',
            'requests'   => ['index', 'store', 'update', 'destroyBatch', 'restore', 'create', 'edit'], // 默认的 action 对应的 request 定义
            'stub'       => 'controller-admin',
            'trait_stub' => 'controller-base-action-trait',
            'route'      => 'routes/admin.php',
        ],
        'api' => [
            'name'       => ['zh-CN' => '接口', 'en' => 'Api'],
            'path'       => 'app/Api/Controllers/',
            'requests'   => ['index'], // 默认的 action 对应的 request
            'stub'       => 'controller-api',
            'trait_stub' => 'controller-api-base-action-trait',
            'route'      => 'routes/api.php',
        ],
    ],

    /**
     * 与 controller 中的 app 配置对应，两个原则：
     * 1、要么每个 app 都是不同的 project_id
     * 2、要么所有 app 都是同一个 project_id
     * 因为在创建接口分组时，不同 project_id 将节省一级目录，减少每次要多点一次鼠标的体验
     */
    'api_fox' => [
        'admin' => [
            'project_id' => env('SCAFFOLD_API_FOX_ADMIN_PID', ''),
            'token'      => env('SCAFFOLD_API_FOX_ADMIN_TOKEN', ''),
        ],
        'api' => [
            'project_id' => env('SCAFFOLD_API_FOX_API_PID', ''),
            'token'      => env('SCAFFOLD_API_FOX_API_TOKEN', ''),
        ],
    ],

    /**
     * 生成时 资源 及  对应的类，可以自定义、及修改类的位置
     * 建议：复制文件，自定义于业务系统中，减低业务系统与本工具耦合性
     */
    'class' => [
        'resources' => [
            'base'          => 'Mooeen\Scaffold\Foundation\BaseResource',
            'collection'    => 'Mooeen\Scaffold\Foundation\BaseResourceCollection',
            'form'          => 'Mooeen\Scaffold\Foundation\FormWidgetCollection',
            'table_columns' => 'Mooeen\Scaffold\Foundation\TableColumnsCollection',
            'columns'       => 'Mooeen\Scaffold\Foundation\ColumnsCollection',
        ],
        'actions'      => 'Mooeen\Scaffold\Foundation\Actions',
        'controller'   => 'Mooeen\Scaffold\Foundation\Controller',
        'form_request' => 'Mooeen\Scaffold\Foundation\FormRequest',
    ],
];
