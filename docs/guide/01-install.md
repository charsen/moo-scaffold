---
title: 01 · 安装与初始化
group: Scaffold 操作手册
order: 40
---
# 01 · 安装与初始化

> 从零到打开 `/scaffold`、生成第一套 CRUD。按顺序走 7 步。环境:Laravel 12 · PHP 8.2+。

## 前置:Composer

moo-scaffold 已按 Composer 包发布。正常项目不需要配置额外 VCS 仓库:

```bash
composer require --dev charsen/moo-scaffold:^2.1
```

如需本地开发包本身,再使用 path repository 指向本地源码:

```json
{
    "require-dev": {
        "charsen/moo-scaffold": "*"
    },
    "repositories": {
        "scaffold": { "type": "path", "url": "../moo-scaffold" }
    }
}
```

## 1. 装包

```bash
composer require --dev charsen/moo-scaffold:^2.1
php artisan list | grep moo        # 看到 moo:init / moo:free 等即成功
```

## 2. 初始化

```bash
php artisan moo:init "你的名字"
```

写 `SCAFFOLD_AUTHOR`(生成代码注释署名)+ 建 `scaffold/database/`、`storage/scaffold/` 目录。

## 3. 发布配置 + 静态资源

```bash
php artisan vendor:publish --provider="Mooeen\\Scaffold\\ScaffoldProvider" --tag=config
php artisan vendor:publish --provider="Mooeen\\Scaffold\\ScaffoldProvider" --tag=public --force
```

得到 `config/scaffold.php`(改前缀 / hosts / 各开关)+ `public/vendor/scaffold/*`(浏览器加载这份)。

> ⚠️ 以后改了包内 `public/*.js` / `*.css` / `*.scss`,要重跑 `--tag=public --force`(SCSS 先编译)否则浏览器看不到。

## 4. 留路由标记 + 注册宏(生成器依赖,必做)

每个 `route_mode = resource` 的应用端路由文件（默认 `routes/admin.php`、`routes/mobi.php`、`routes/web.php`）都留一行标记：

```php
// :insert_code_here:do_not_delete
```

`config/scaffold.php` 的 `controller` 是应用端注册表。默认端为 `admin`、`mobi`、`web`；RPA、数据屏等业务端由 host 追加。端 key 表示消费者边界，不是 HTTP API 的同义词；API 文档仍使用独立的 `api` 配置段、`moo:api` 命令和 `/scaffold/api` 页面。

每个端显式声明 `path`、Request/Resource 路径、stub 和 `route`。标准资源路由用 `route_mode = resource`；自定义业务路由用 `route_mode = manual`，生成器不会自动插入 `Route::iResource`。

旧 host 若曾把 `api` 当作移动端，升级时应一次性改为 `mobi`，不保留双 key 兼容层：

1. 将 `App\\Api`、`app/Api`、`routes/api.php` 分别改为 `App\\Mobi`、`app/Mobi`、`routes/mobi.php`。
2. 同步修订 schema 的 `controller.app` / `controller.resource`、middleware group、limiter 与 host 路由引用。对外 URL 可继续使用 `/app`、`/api`等原有前缀；内部应用端名与 HTTP 前缀是两个概念。
3. 运行 `composer dump-autoload`、`php artisan moo:fresh`、`php artisan moo:auth mobi`；需要接口文档时再运行 `php artisan moo:api mobi -a`。

`AppServiceProvider::boot()` 注册后台路由用的 `iResource` 宏:

```php
Route::macro('iResource', function ($name, $controller) {
    Route::get($name.'/trashed', $controller.'@trashed')->name($name.'.trashed');
    Route::delete($name.'/forever/{id}', $controller.'@forceDestroy')->name($name.'.forceDestroy');
    Route::delete($name.'/batch', $controller.'@destroyBatch')->name($name.'.destroyBatch');
    Route::patch($name.'/restore', $controller.'@restore')->name($name.'.restore');
    Route::resource($name, $controller);
});
```

> 用 `moo:model -F` 生成 Factory 时,`database/seeders/DatabaseSeeder.php` 也要留 `//:auto_insert_code_here::do_not_delete`。

## 5. 建第一个账号

```bash
php artisan moo:account:add charsen --password=xxx --role=admin
```

`/scaffold` 受登录保护;首个账号用 CLI,之后增删改全走 `/scaffold/accounts`。账号存 `scaffold/accounts.yaml`(密码 bcrypt)。

## 6. 打开后台

```text
http://你的项目/scaffold
```

用刚建的账号登录,看到首页即成功。前缀默认 `scaffold`,改 `config/scaffold.php` 的 `route.prefix`。

## 7. 生成第一套 CRUD(验证打通)

```bash
php artisan moo:schema Light          # 建 scaffold/database/Light.yaml
# 编辑 Light.yaml,字段语法参考 docs/schema_demo.yaml
php artisan moo:fresh                  # 解析 schema 到缓存
php artisan moo:free admin Light -a    # 一键生成 Model/Resource/Controller/Request/Migration/i18n/ACL/API
```

schema 写法见 [02-schema-codegen.md](02-schema-codegen.md),命令详解见 [03-cli-reference.md](03-cli-reference.md)。

## env 速查

| Env | 默认 | 说明 |
|---|---|---|
| `SCAFFOLD_AUTHOR` | `''` | 生成代码注释署名 |
| `SCAFFOLD_AUTH_TTL_MINUTES` | `1440` | 登录有效期(分钟) |
| `SCAFFOLD_CONFIG_READONLY` | `false` | 强制 `/scaffold/*` 只读 |
| `SCAFFOLD_MIDDLEWARE` | `null` | 逗号分隔的额外中间件 |

> AI 翻译(DeepSeek)配置不走 env,在 `/scaffold/config → AI 配置` 页面填。其余 key 全在 `config/scaffold.php`。

## 装不上 / 跑不通?

- **`php artisan list` 没 `moo:*`** → `composer dump-autoload`,确认没禁 package discovery。
- **路由没插进去** → 检查 `:insert_code_here:do_not_delete` 标记还在。
- **生产点保存报错** → 设计如此,生产只读(见 [12-security.md](12-security.md))。
- **目录不一样** → 控制器/Resource/Request 默认落在 `app/Admin`、`app/Mobi`、`app/Web` 对应目录，改 `config/scaffold.php` 的 `controller.{app}.*`。
