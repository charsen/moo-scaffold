# 🎉 Scaffold 介绍

(未完善，待续...)

## 为何而来

“约定大于配置” 、“以机械化代替手工化作业”

支持多语言，默认 `{en, zh-CN}`

## 安装

通过 [composer](https://laravel-china.org/composer) 安装

```sh
composer require --dev charsen/laravel-scaffold
```

- (可选)发布配置文件到，若需要调整配置的话：

```sh
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider --tag=config
```

将会发布 `scaffold.php` 到 `config` 目录下.

- 发布前端公共资源包到 public 目录下：

```sh
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider --tag=public --force
```

## 初始化（记录编码作者及创建目录）

- 生成的 controller, model, migration 会在注释里加上作者和日期

```sh
php artisan scaffold:init "`author`"
```

**Example:**

```sh
php artisan scaffold:init "Charsen <https://github.com/charsen>"
```

## 关于目录结构的约定

1. `app_path()` 路径下的优先理解为管理后台。
2. 若需要归集某个端的功能，如 `App`，可在 `app_path()` 下创建 `App` 目录
