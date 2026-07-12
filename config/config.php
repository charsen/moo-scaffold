<?php declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-06-28 21:29
 * @Description: Laravel Scaffold Config，配置中所有的路由，都是相对于 base_path() （必须在base_path()路径下）
 */

return [
    /**
     * 当前编码作者信息
     */
    'author' => env('SCAFFOLD_AUTHOR', ''),

    /**
     * 是否只在本地开发环境使用命令行
     */
    'only_in_local' => true,

    /**
     * 是否使用雪花 ID 主键算法
     */
    'snow_flake_id' => true,

    /**
     * 后台，授权验证
     */
    'authorization' => [
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
     * 数据库 schema 文件路径
     */
    'database' => [
        'schema' => 'scaffold/database/',
    ],

    /**
     * API 接口配置文件的路径
     */
    'api' => [
        'schema'  => 'scaffold/api/',
        'history' => 'scaffold/api/history/',
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
            'name'          => ['zh-CN' => '后台管理', 'en' => 'Admin'],
            'api_name'      => '后台管理',  // 接口调试工具中的显示名称
            'path'          => 'app/Admin/Controllers/',
            'requests'      => ['index', 'store', 'update', 'destroyBatch', 'create', 'edit'], // 默认的 action 对应的 request 定义
            'request_path'  => 'app/Admin/Requests/',
            'resource_path' => 'app/Admin/Resources/',
            'stub'          => 'controller-admin',
            'trait_stub'    => 'controller-admin-base-action-trait',
            'route'         => 'routes/admin.php',
            // 包提供的额外 admin 模块：模块名 => 控制器命名空间（默认空）。这些控制器不在 host 的
            // controller.admin.path 下、生产环境又位于 vendor/，列在此处后即纳入 ACL（moo:auth）/
            // API 文档（moo:api）/ 路由调试 / 接口调试，并按此命名空间解析 FQCN。
            // 例：['System' => 'Mooeen\\System\\Http\\Controllers\\Admin']
            'extra_modules' => [],
        ],
        'api' => [
            'name'          => ['zh-CN' => '接口', 'en' => 'Api'],
            'api_name'      => '客户端接口',  // 接口调试工具中的显示名称
            'path'          => 'app/Api/Controllers/',
            'requests'      => ['index'], // 默认的 action 对应的 request
            'request_path'  => 'app/Api/Requests/',
            'resource_path' => 'app/Api/Resources/',
            'stub'          => 'controller-api',
            'trait_stub'    => 'controller-api-base-action-trait',
            'route'         => 'routes/api.php',
        ],
    ],

    /**
     * 全局 Resource 兜底路径
     * 当 controller.{app}.resource_path 未配置，且 schema 也没有指定 resource app 时使用
     */
    'resource' => [
        'path' => 'app/Http/Resources/',
    ],

    /**
     * moo:test 生成的 Pest 路由契约冒烟测落点
     * 相对 base_path()（常规），或写绝对路径（多项目共享 / 测试场景）。
     */
    'tests' => [
        'path' => 'tests/Feature/',
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
        'actions'    => 'Mooeen\Scaffold\Foundation\Actions',
        'controller' => 'Mooeen\Scaffold\Foundation\Controller',
        // 注：FormRequest 基类 / 校验规则类（NumericArray / Mobile / DatetimeArray）不在此配置。
        // 这些只在 codegen 时使用，固定用 scaffold 自带类，见 CreateControllerGenerator。
    ],

    /**
     * Scaffold 路由配置
     */
    'route' => [
        'prefix' => 'scaffold',
        // 默认不挂额外中间件组（[]）。需要 session 的认证路由已在 routes.php 内层 group 显式挂
        // StartSession / VerifyCsrfToken；外层默认成 ['web'] 会让 UI 路由吃全局 CSRF，曾踩 419
        // 「CSRF token mismatch」（见 routes.php 顶部注释）。要额外中间件用 SCAFFOLD_MIDDLEWARE env 注入。
        // 默认挂 web group（session/cookie/csrf），AccountController 等用 session()->flash() 需要
        'middleware' => env('SCAFFOLD_MIDDLEWARE') ? explode(',', env('SCAFFOLD_MIDDLEWARE')) : ['web'],
    ],

    /**
     * Scaffold 简单账号认证
     * 账号来源：scaffold/accounts.yaml（AccountStore）；
     * 首次部署若 yaml 不存在，用 `php artisan moo:account:add` 创建首个账号。
     */
    'auth' => [
        'enabled'     => true,
        'cookie_name' => 'scaffold_auth',
        // 登录会话有效期（分钟）。默认 24h；安全敏感场景可调短，便利场景可调长。
        // cookie 现在用 AES-256 加密 + HMAC 签名，但仍建议尽量短以缩小被盗用窗口。
        'ttl_minutes' => (int) env('SCAFFOLD_AUTH_TTL_MINUTES', 60 * 24),
    ],

    /**
     * 接口调试的域名配置(宿主项目 publish 后覆盖到 config/scaffold.php 改成自己的)
     */
    'hosts' => [
        '开发环境' => 'http://localhost',
        '正式环境' => 'https://example.com',
    ],

    /**
     * 接口调试代理配置
     * 故意不提供 verify_tls 开关:HTTPS 证书有问题就报错,不留绕过路径。
     * 也不 follow redirect:server 重定向(如 http → https)直接显示 301/302,逼你修 hosts 配置。
     */
    'proxy' => [
        'timeout' => (int) env('SCAFFOLD_PROXY_TIMEOUT', 30),
    ],

    /**
     * 生成前端资源的配置(仅当你有独立前端 SPA 项目跟此 Laravel 后端 sibling 部署时才用)
     * 默认 `../admin/src/` 假设前端在 `<Laravel-root>/../admin/`。无前端项目可忽略全部 frontend.* key,
     * `moo:free -a` 跑前端生成步骤会自动 skip。
     */
    'frontend' => [
        'src'    => '../admin/src/',
        'models' => '../admin/src/models/',
        'views'  => '../admin/src/views/',
        'types'  => '../admin/types/',
    ],

    /**
     * Plan 18：配置 Web 管理界面
     */
    'config_ui' => [
        'enabled' => (bool) env('SCAFFOLD_CONFIG_UI', true),
        // 强制只读总开关（即便 APP_ENV=local 也只读）
        'readonly' => (bool) env('SCAFFOLD_CONFIG_READONLY', false),
        // env 镜像页 / diff 里需要掩码的字段（substring 匹配）
        'sensitive_keys' => ['PASSWORD', 'SECRET', 'KEY', 'TOKEN'],
    ],

    /**
     * Plan 19：Designer AI 翻译（DeepSeek 等 OpenAI 兼容上游）。
     * 2026-06 改为 GUI 编辑（/scaffold/config/ai），配置存 scaffold/ai.yaml（跟 accounts.yaml
     * 一样入 git、随仓同步）；运行时读 yaml（不走 env），改完即时生效、config:cache 安全。
     * 不再用 SCAFFOLD_AI_* env。⚠ api_key 明文存储，确保本仓私有。
     */
    'ai' => [
        'yaml_path' => 'scaffold/ai.yaml',
    ],

    /**
     * plan-52：文档中心。MD 文档存 docs.path（相对 base_path，入 git，随仓同步）。
     * 团队在本地编辑、生产环境只读预览（写路由走 EnforceScaffoldWritable 的 docs/* 锁）。
     * 流程图用 Mermaid，渲染在隔离 iframe（/scaffold/docs/_diagram，单独放宽 CSP）。
     */
    'docs' => [
        'path' => env('SCAFFOLD_DOCS_PATH', 'scaffold/docs'),
    ],

    /**
     * Plan 18：开发人员账号（独立 YAML 存储）
     */
    'accounts' => [
        // YAML 主文件，路径相对 base_path()；跟随 git 同步（团队共享 + 远程部署）
        'yaml_path' => env('SCAFFOLD_ACCOUNTS_YAML', 'scaffold/accounts.yaml'),
    ],

    // 扩展包(plan-53)**无注册表**:凡 composer 装的包带 scaffold/database/ 目录即被
    // PackageRegistry 自动发现;命名空间取包 psr-4,目录按全 repo 统一约定,软链装才可写。

    // plan 38：三件套上移——共享雪花单例 scaffold.snowflake 的配置（复用 host 既有 SNOW_FLAKE_* env，与各包原值同源）
    'snowflake' => [
        'data_center_id' => env('SNOW_FLAKE_DATA_CENTER_ID', 1),
        'worker_id'      => env('SNOW_FLAKE_WORKER_ID', 1),
        'start_time'     => env('SNOW_FLAKE_START_TIME', '2021-10-10'),
    ],

];
