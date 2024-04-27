# Laravel Scaffold

## Dev Installing

```sh
$ composer config repositories.scaffold path ../packages/moo-scaffold
$ composer require charsen/moo-scaffold:dev-master
```

## Intro

“约定大于配置” 、“以机械化代替手工化作业”

支持多语言，默认 {en, zh-CN}

待补充... (可先按下面流程操作体验)

## create new laravel

```sh
composer create-project --prefer-dist laravel/laravel "name"
```

## 修改配置 `.env`

1. 开发域名
2. 数据库配置 (需要手动先创建数据库)

## 通过 composer 安装

```sh
composer require --dev charsen/moo-scaffold
```

通过命令行看是否安装成功 `Scaffold`

```sh
php artisan list
```

看到结果中有 scaffold , moo:api ... 等就是已经安装成功

## 初始化开发者信息（自己）及初始化目录

```sh
php artisan moo:init "Charsen <https://github.com/charsen>"
```

## 发布配置文件 及 前端公共资源包

- 将会发布 `scaffold.php` 到 `config` 目录下.

```sh
php artisan vendor:publish --provider=Mooeen\\Scaffold\\ScaffoldProvider --tag=config
```

## 创建一个模块的 schema 文件

```sh
php artisan moo:schema `module_name`
```

- 添加 `-f` 覆盖已存在文件
- PS1: 暂不支持多级目录！建议：`module_name = schema_file_name`
- PS2：`controller` 的定义，只支持 `app/Http/Controllers/` 往下 **两级**，更深的层级 **不支持**!!!

将会生成 `schema` 文件 `+ ./scaffold/database/<module_name>.yaml`

设计模块下的数据表，- 具体说明详见 demo:

[docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)

## 创建数据迁移文件

```sh
php artisan moo:migration `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-m` 会执行 `php artisan migrate`
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan moo:fresh`

## 创建模型文件

> 前置动作：再 DataBaseSeeder.php 中加入 //:auto_insert_code_here::do_not_delete ，一遍生成代码时

```sh
php artisan moo:model `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 默认生成 Trait 文件
- 添加 `-f` 覆盖已存在文件 model 和 factory 文件
- 添加 `-F` 生成对应的 `factory` 文件，并更新到 `database/seeds/DatabaseSeeder.php`


## 设置 `cors` 跨域设置

修改 `config/cors.php` 加入 后台路径

```php
    //...
    'paths' => ['admin/*', 'api/*'],
    //...
```

## 路由设置

### 注册新方法 `iResource`

添加 `route` 的 `iResource` 方法，于 `app/Providers/RouteServiceProvider.php` 文件中：

```php
    //...
    public function boot()
    {
        //
        $this->registerMacros();

        parent::boot();
    }
    //...
    protected function registerMacros()
    {
        Route::macro('iResource', function($name, $controller) {
            Route::get($name . '/trashed', $controller . '@trashed')->name($name . '.trashed');
            Route::delete($name . '/forever/{id}', $controller . '@forceDestroy')->name($name . '.forceDestroy');
            Route::delete($name . '/batch', $controller . '@destroyBatch')->name($name . '.destroyBatch');
            Route::patch($name . '/restore', $controller . '@restore')->name($name . '.restore');
            Route::resource($name, $controller);
        });
    }
    //...
```


### 配置 `admin` 路由对应的中件间

在 `app/Http/Kernel.php` 中添加

```php
    //...
    protected $middlewareGroups = [
        //...
        'admin' => [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        //...
    ];
    //...
```

### 注册 `admin` 路由配置文件

于 `app/Providers/RouteServiceProvider.php` 文件中，添加：

```php
    //...
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        //
        $this->mapAdminRoutes();
    }

    //...
    protected function mapAdminRoutes()
    {
        Route::prefix('admin')
             ->middleware('admin')
             ->group(base_path('routes/admin.php'));
    }
    //...
```


## 创建控制器

基础控制器支持自定义，可修改 `config/scaffold.php` 中的配置；

```sh
php artisan moo:controller `app_name` `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件（Request 文件不会被覆盖，需要手动删除）
- 同时会生成对应的 `From Request` 对象于 `app/Http/Requests/` 路径下（目录层次与 `Controller` 的一致）
- 如果之前已经有 trait 文件了，并且存在 controller 文件，则不再生成 request 文件，避免删除了又重新生成
- 
_**!!! PS: !!!**_

- `controller` 里的 `action` 是生成 `接口文档及调试` 的依据，`one action = one api`
- 请先 **认真** 设置 `From Request` 里的验证规则，因为类里的验证规则是生成 `api` 及 `表单控件` 时的数据来源


### 执行命令生成接口对应的配置文件

生成接口测试 `yaml` 文件，后续调整 `controller` 时，若有删减会提示需要手动去掉 `yaml` 中对应的 `action` ，若有增加则会自动追加到 `yaml` 文件。

```sh
php artisan moo:api `namesapce`
```

- `namesapce` 非必写，若不写会有提示做选择（`app/controllers` 下的某个目录，或多级目录）
- 添加 `-f` 覆盖已存在文件
- 添加 `-i` 忽略用 `controller` 里的 `actions` 求交集 (见下方 PS2)
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan moo:fresh`

**PS1:**

- api 里的参数 默认通过 `From Request` 对象 `验证规则` 里读取
- 默认的接口名称通过 `"反射"` 控制器中动作的注释来获取

**PS2:**

```php
Route::iResource('departments', \App\Admin\Controllers\System\DepartmentController::class);
```

- 要先设置好路由规则，程序通过 `Route::getRoutes()` 获取接口地址（但由于用了 `Route::resources`，实际 `action` 可能没那么多）
- 用路由与控制器的 `actions` 求交集，得出真实的接口
- 生成时：默认附加新的 `action` 到对应的接口 `yaml` 文件，
- 生成时：若有 `action` 被删减会提醒，需要 `手工删除` 接口 `yaml` 文件中的代码

## 更新 i18n 文件

```sh
php artisan moo:i18n
```

- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan moo:fresh`
- 目前支持 `英文` 、`中文` 两个语种
- 可先润色 `scaffold/database/_fields.yaml` 里的内容，此文件会自动根据数据表的字段，添加或删掉项目

## 快速导航

- https://learnku.com/docs/laravel/8.5/validation/10378#189a36
- https://fakerphp.github.io/formatters/numbers-and-strings/
- https://github.com/fzaninotto/Faker/tree/master/src/Faker/Provider/zh_CN
