# Laravel Scaffold

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
composer require --dev charsen/laravel-scaffold
```

通过命令行看是否安装成功 `Scaffold`

```sh
php artisan list
```

看到结果中有 scaffold , scaffold:api ... 等就是已经安装成功

## 初始化开发者信息（自己）及初始化目录

```sh
php artisan scaffold:init "Charsen <https://github.com/charsen>"
```

## 发布配置文件 及 前端公共资源包

- 将会发布 `scaffold.php` 到 `config` 目录下.

```sh
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider --tag=config
```

- 发布前端公共资源包到 public 目录下

```sh
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider --tag=public --force
```

## 访问查看结果

在浏览器中打开 `http://<domain>/scaffold` , 网页正常打开，样式正常显示（因没数据，其它链接进入时均提示出错）

## 创建一个模块的 schema 文件

```sh
php artisan scaffold:schema `module_name`
```

- 添加 `-f` 覆盖已存在文件
- PS1: 暂不支持多级目录！建议：`module_name = schema_file_name`
- PS2：`controller` 的定义，只支持 `app/Http/Controllers/` 往下 **两级**，更深的层级 **不支持**!!!

将会生成 `schema` 文件 `+ ./scaffold/database/<module_name>.yaml`

设计模块下的数据表，- 具体说明详见 demo:

[docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)

## 刷新/生成 schema 数据缓存

在每次修改完 `schema` 文件后，均需要执行此命令，刷新缓存数据；

```sh
php artisan scaffold:fresh
```

- 添加 `-c` 清空数据后重建

## 查看数据库文档

```sh
http://<domain>/scaffold/db
```

- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取

**PS：**

- 此时是很好的检查表设计的环节，表名、字段、类型等等；
- 及时调整后 `artisan scaffold:fresh` ，偶尔可以加入 `-c` 会清掉错误的缓存文件。

## 创建数据迁移文件

```sh
php artisan scaffold:migration `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-m` 会执行 `php artisan migrate`
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`

## 创建模型文件

```sh
php artisan scaffold:model `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 默认生成 Trait 文件
- 添加 `-t` 重新生成 Trait 文件（若 model 存在时需要覆盖更新）
- 添加 `-f` 覆盖已存在文件
- 添加 `--factory` 同时生成对应的 `factory` 文件，并更新到 `database/seeds/DatabaseSeeder.php`
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`

## 添加 资源类

创建 `Resources` 文件夹，路径：`app/Http/Resources/`

`BaseResource`、`FormWidgetCollection`、`TableColumnsCollection` 已存在于 `scaffold` 中，支持自定义配置，可修改 `config/scaffold.php` 中的配置，通过自定义路径后减低 `scaffold` 与业务系统的关联性；

### 接口结果基础类 `BaseResource`

代码见 [BaseResource](https://github.com/charsen/laravel-scaffold/blob/master/src/Http/Resources/BaseResource.php)

### 表单组件 `FormWidgetCollection`

代码见 [FormWidgetCollection](https://github.com/charsen/laravel-scaffold/blob/master/src/Http/Resources/FormWidgetCollection.php)

### 表格字段 `TableColumnsCollection`

代码见 [TableColumnsCollection](https://github.com/charsen/laravel-scaffold/blob/master/src/Http/Resources/TableColumnsCollection.php)

### 详情字段 `ColumnsCollection`

代码见 [ColumnsCollection](https://github.com/charsen/laravel-scaffold/blob/master/src/Http/Resources/ColumnsCollection.php)

## 创建控制器

基础控制器支持自定义，可修改 `config/scaffold.php` 中的配置；

```sh
php artisan scaffold:controller `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件（Request 文件不会被覆盖，需要手动删除）
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 同时会生成对应的 `From Request` 对象于 `app/Http/Requests/` 路径下（目录层次与 `Controller` 的一致）
- 若 `routes/admin.php` 不存在，则会自动生成，存在会将此次的路由规则添加进去

_**!!! PS: !!!**_

- `controller` 里的 `action` 是生成 `接口文档及调试` 的依据，`one action = one api`
- 请先 **认真** 设置 `From Request` 里的验证规则，因为类里的验证规则是生成 `api` 及 `表单控件` 时的数据来源

## 设置 `cors` 跨域设置

修改 `config/cors.php` 加入 后台路径

```php
    //...
    'paths' => ['admin/*', 'api/*'],
    //...
```

## 路由设置

### 注册新方法 `resourceHasTrashes`

添加 `route` 的 `resourceHasTrashes` 方法，于 `app/Providers/RouteServiceProvider.php` 文件中：

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
        Route::macro('resourceHasTrashes', function($name, $controller) {
            Route::get($name . '/trashed', $controller . '@trashed')->name($name . '.trashed');
            Route::delete($name . '/forever/{id}', $controller . '@forceDestroy')->name($name . '.forceDestroy');
            Route::delete($name . '/batch', $controller . '@destroyBatch')->name($name . '.destroyBatch');
            Route::patch($name . '/restore', $controller . '@restore')->name($name . '.restore');
            Route::resource($name, $controller);
        });
    }
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
             ->namespace($this->namespace)
             ->group(base_path('routes/admin.php'));
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
            'throttle:60,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        //...
    ];
    //...
```

## 生成接口配置文件

### 第一次

先手动添加目录转换文件 `scaffold/api/_menus_transform.yaml`

```yaml
###
# 转换 api 调试工具菜单
#
# 一个目录一行，显示时会按此顺序
##
'Index': '根目录'
'System': '系统管理'
```

### 执行命令生成接口对应的配置文件

生成接口测试 `yaml` 文件，后续调整 `controller` 时，若有删减会提示需要手动去掉 `yaml` 中对应的 `action` ，若有增加则会自动追加到 `yaml` 文件。

```sh
php artisan scaffold:api `namesapce`
```

- `namesapce` 非必写，若不写会有提示做选择（`app/controllers` 下的某个目录，或多级目录）
- 添加 `-f` 覆盖已存在文件
- 添加 `-i` 忽略用 `controller` 里的 `actions` 求交集 (见下方 PS2)
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`

**PS1:**

- api 里的参数 默认通过 `From Request` 对象 `验证规则` 里读取
- 可在 `api` 的 `yaml` 配置文件中重写 `url_params` 及 `body_params` 来覆盖默认的参数设置
- 默认的接口名称通过 `"反射"` 控制器中动作的注释来获取
- api demo [docs/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/api_demo.yaml)

**PS2:**

```php
Route::resourceHasTrashes('departments', 'Admin\\Personnels\\DepartmentController');
```

- 要先设置好路由规则，程序通过 `Route::getRoutes()` 获取接口地址（但由于用了 `Route::resources`，实际 `action` 可能没那么多）
- 用路由与控制器的 `actions` 求交集，得出真实的接口
- 生成时：默认附加新的 `action` 到对应的接口 `yaml` 文件，
- 生成时：若有 `action` 被删减会提醒，需要 `手工删除` 接口 `yaml` 文件中的代码

## 更新 i18n 文件

```sh
php artisan scaffold:i18n
```

- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 目前支持 `英文` 、`中文` 两个语种
- 可先润色 `scaffold/database/_fields.yaml` 里的内容，此文件会自动根据数据表的字段，添加或删掉项目

## 查看接口文档

先关闭权限验证，`config/scaffold.php` 中

```php
    //...
    'authorization' => [
        // 是否开启 验证验证
        'check' => FALSE,
    //...
```

修改本地语言设置，`config/app.php` 中

```php
    //...
    'locale' => 'zh-CN',
    //...
```

- 在浏览器中打开 `http://<domain>/scaffold/api`
- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取
