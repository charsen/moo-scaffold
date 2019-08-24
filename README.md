# Laravel Scaffold

## 1. 关于（未完，待续）
“约定大于配置” 、“以机械化代替手工化作业”

支持多语言，默认 {en, zh-CN}

## 2. 功能（未完，待续）

### 2.1 migration
- 单个数据表的 migration

### 2.2 controller 部分
- create: 创建表单
- edit: 编辑表单
- index: 列表
- trashed: 回收站
- store: 创建
- update: 编辑
- show: 查看详情
- destroy: 删除
- destroyBath: 批量删除
- restore: 恢复（可批量）

#### 2.2.1 关于目录结构
1. `app_path()` 路径下的优先理解为管理后台。
每个 controller 头部注释格式如下（在生成 `actions.php` 时需要用到，`zh-cn|en` 是为了做多语言）：
```php
/**
 * 授权管理控制器
 *
 * @package_name {zh-CN: 后台管理 | en: Admin}
 * @module_name {zh-CN: 授权管理 | en: Authorizations}
 * @controller_name {zh-CN: 授权管理 | en: Authorziation Role}
 *
 */
```

2. 若需要归集某个端的功能，如 App，可在 `app_path()` 下创建 App 目录
```php
/**
 * 部门控制器
 *
 * @package_name {zh-CN: App | en: App}
 * @module_name {zh-CN: 人事管理 | en: Personnels}
 * @controller_name {zh-CN: 部门管理 | en: Management Department}
 *
 * @package App\Http\Controllers\Personnels;
 * @author  Charsen <https://github.com/charsen>
 * @date    2019-08-20 20:39:48
 */
```

注：在 controller 头部不写这块注释代码，`scaffold:auth` command 时不会生成到 `actions.php` 中

#### 2.2.2controller 对应的 FormRequest 对象
会同步生成，一个 action 对应一个 actionRules


### 2.3 Routes 部分
若存在同一 url 有多种 request method 时，分开写，以对应不同的 controller action
在权限设置时，可以在 controller boot() 中做转换，达到同一个关联权限设置；
```php
Route::get('roles/{id}/create-personnels', 'Authorizations\AuthController@createPersonnels');
Route::post('roles/{id}/create-personnels', 'Authorizations\AuthController@storePersonnels');
```


### 2.4 model 部分
- boolean 自动转换
- 整形 转 浮点数 (repository 的验证规则转换为 numeric)
- 数据字典 添加 appends 及 getAttribute 函数


### 2.5 多语言文件
- 生成数据库表所有字段 及 模型字典字段多语言
- resources/lang/{en, zh-CN}/model.php
- resources/lang/{en, zh-CN}/validation.php


### 2.6 授权文件
- app/ACL.php （非必须，仅作为人工核查）
- config/actions.php (内含白名单)
- resources/lang/{en, zh-CN}/actions.php


## 3. 安装
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


## 4. 使用方法
### 4.1 初始化（记录编码作者及创建目录）
- 生成的 controller, model, migration 会在注释里加上作者和日期
```sh
php artisan scaffold:init `author`

```
**Example:**
```
php artisan scaffold:init "Charsen <https://github.com/charsen>"
```


### 4.2 创建某模块的 schema 表
- 数据库设计及对应关系
```sh
php artisan scaffold:schema `module_name`
```
- 添加 `-f` 覆盖已存在文件
- PS1: 暂不支持多级目录！建议：`module_name = schema_file_name`
- PS2：`controller` 的定义，只支持 `app/Http/Controllers/` 往下**两级**，更深的层级**不支持**!!!

**Example:**
```
php artisan scaffold:schema Personnels
```

### 4.3 刷新/生成 schema 数据缓存
```sh
php artisan scaffold:fresh
```
- 添加 `-c` 清空数据后重建
- 具体说明详见 demo [docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)


### 4.4 查看数据库文档
```sh
http://{{url}}}/scaffold/db
```
- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取

**PS：**
- 此时是很好的检查表设计的环节，表名、字段、类型等等；
- 及时调整后 `artisan scaffold:fresh` ，偶尔可以加入 -c 会清掉错误的缓存文件。


### 4.5 创建数据迁移文件
```sh
php artisan scaffold:migration `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-m` 会执行 `php artisan migrate`
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`


### 4.6 创建模型文件
- `schema_file_name` 非必写，若不写会有提示做选择
```sh
php artisan scaffold:model `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 添加 `--factory` 同时生成 model 对应的 factory 文件，并更新 `DatabaseSeeder`

**Example:**
```
php artisan scaffold:model personnels
```


### 4.7 创建控制器
```sh
php artisan scaffold:controller `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件（Request 文件不会被覆盖，需要手动删除）
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 同时会生成对应的 `From Request` 对象于 `app/Http/Requests/` 路径下（目录层次与 Controller 的一致）

_**!!! PS: !!!**_
- `controller` 里的 `action` 是生成 接口文档及调试 的依据，`one action == one api`
- 请先 **认真** 设置 `From Request` 里的验证规则，因为类里的验证规则是生成 `api` 及 `表单控件` 时的数据来源


### 4.8 生成接口配置文件
```sh
php artisan scaffold:api `namesapce`
```
- `namesapce` 非必写，若不写会有提示做选择（`app/controllers` 下的某个目录，或多级目录）
- 添加 `-f` 覆盖已存在文件
- 添加 `-i` 忽略用 `controller` 里的 `actions` 求交集
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`

**PS1:**
- api 里的参数 默认通过 `From Request` 对象 验证规则里读取
- 可在 api 的 yaml 配置文件中重写 url_params 及 body_params 来覆盖 默认的参数设置
- 默认的接口名称能过 "反射" 控制器中动作的注释来获取
- api demo [docs/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/api_demo.yaml)

**PS2:**
```php
Route::resourceHasTrashes('departments', 'Admin\\Personnels\\DepartmentController');
```
- 要先设置好路由规则，程序通过 `Route::getRoutes()` 获取接口地址（但由于用了 `Route::resources`，实际可能没那么多）
- 用路由与控制器的`actions`求交集，得出真实的接口
- 生成时：默认是附加新的`action`到对应的配置文件，若有`action`被删减了会提醒，需要手工删除接口配置文件的代码


### 4.9 更新 i18n 文件
```sh
php artisan scaffold:i18n
```
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 目前支持 `英文` 、`中文` (需要手动在 `resources/lang/` 下创建 `zh-CN` 目录) 两个语种
- 可先润色 `scaffold/database/_fields.yaml` 里的内容，此文件会自动根据数据表的字段，添加或删掉项目


### 4.10 查看接口文档
```
http://{{url}}}/scaffold/api
```
- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取


### 4.11 更新 Authorization 文件
```sh
php artisan scaffold:auth
```
- 更新 `./app/ACL.php`
- 更新 `./resources/lang/{en, zh-CN}/actions.php` (维护一处注释，同步多个语言文件)
- 更新 `./app/config/actions.php`
- 需要做授权的 `action` 必须在注释中写 `@acl {zh-CN: 中文 | en: English}` 否则会被加入白名单


### 4.12 Free : “释放双手”
```sh
php artisan scaffold:free  `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 执行 `artisan scaffold:fresh` 更新缓存数据
- 生成 `mode`, `migration` , `controller`, `api` 相关文件
- 执行 `artisan scaffold:i18` 更新多语言文件
- 执行 `artisan scaffold:auth` 更新权限验证文件
- 询问是否执行 `artisan migrate` 创建数据表？


## 5. 文档
- schema demo [docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)
- api demo [docs/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/api_demo.yaml)


## 6. Changelog
Please see [CHANGELOG](*CHANGELOG.md*) for more information what has changed recently.


## 7. Security
If you discover any security related issues, please email 780537@gmail.com instead of using the issue tracker.


## 8. Thanks
- [Lamtin](https://github.com/Lamtin)


## 9. License
The MIT License (MIT). Please see [License File](*LICENSE.md*) for more information.
