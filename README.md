# Laravel Scaffold

## 功能（待写...）

### model 部分
- boolean 自动转换
- 整形 转 浮点数 (repository 的验证规则转换为 numeric)
- 数据字典 添加 appends 及 getAttribute 函数

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


## 使用方法
### 1. 初始化（记录编码作者及创建目录）
- 生成的 controller, model, repository, migration 会在注释里加上作者和日期
```sh
php artisan scaffold:init `author`
```


### 2. 创建某模块的 schema 表
- 主要是数据库设计及对应关系
```sh
php artisan scaffold:schema `module_name`
```
- 添加 `-f` 覆盖已存在文件
- PS: 暂不支持多级目录！所有 `module_name = file_name`


### 3. 刷新/生成 schema 数据缓存
```sh
php artisan scaffold:fresh
```
- 添加 `-c` 清空数据后重建
- 具体说明详见 demo [docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)


### 4. 查看数据库文档
```sh
http://{{url}}}/scaffold/db
```
- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取

**PS：** 
- 此时是很好的检查表设计的环节，表名、字段、类型等等；
- 及时调整后 `artisan scaffold:fresh` ，偶尔可以加入 -c 会清掉错误的缓存文件。


### 5. 创建数据迁移文件
```sh
php artisan scaffold:migration `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-m` 执行 `php artisan migrate`
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`


### 6. 创建模型文件
- `schema_file_name` 非必写，若不写会有提示做选择
```sh
php artisan scaffold:model `schema_file_name`
```
- 添加 `-f` 覆盖已存在文件
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`
- 添加 `-r` 同时生成 `repository`


### 7. 创建资源仓库文件 
```sh
php artisan scaffold:repository `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`

**更新 RepositoryServiceProvider**
- 自动更新 `app/Providers/RepositoryServiceProvider.php` 将新生成的类添加到 register() 中
- register() 函数体内最后一行必须是 `//:end-bindings:` 才能实现此功能

_**!!! PS: !!!**_
- 到此，不要急于后续的生成 `controller, api` ，因为类里的验证规则是生成 `api` 及 `表单控件` 时的数据来源
- 请先 **认真** 调整好 `repository` 里的验证规则


### 8. 创建控制器
```sh
php artisan scaffold:controller `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`
- `controller` 里的 `action` 是生成 接口文档及调试 的依据，`one action == one api`


### 9. 生成接口配置文件
```sh
php artisan scaffold:api `namesapce`
```
- `namesapce` 非必写，若不写会有提示做选择（app/controllers 下的某个目录，或多级目录）
- 添加 `-f` 覆盖已存在文件
- 添加 `-i` 忽略用 controller 里的 actions 求交集（若 route.php 里用了 ::resource 方式可能会生成多余的请求）
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`
- 自动获取 `namesapce` 的选择提示内容，只支持 `Http/Controllers/` 往下**两级**，更深的层级**不支持**!!!

**PS1:**
- api 里的参数 默认通过 repository 验证规则里读取
- 可在 api 的 yaml 配置文件中重写 url_params 及 body_params 来覆盖 默认的参数设置
- api demo [docs/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/api_demo.yaml)

**PS2:**
- 要先设置好路由规则，程序通过 `Route::getRoutes()` 获取接口地址（但由于用了 `Route::resources`，实际可能没那么多）
- 用路由与控制器的 action 求交集，得出真实的接口
- 生成时：默认是附加新的 action 到对应的配置文件，若有action被删减了会提醒，需要手工删除接口配置文件的代码


### 10. 查看接口文档
```
http://{{url}}}/scaffold/api
```
- 字段名称会优先从 `scaffold/database/_fields.yaml` 读取


### 11. 更新 i18n 文件
```sh
php artisan scaffold:i18n
```
- 添加 `--fresh` 刷新缓存数据，等于先执行 `artisan scaffold:fresh`
- 目前支持两个 英文 、中文(需要手动在 `resources/lang/` 下创建 `zh-CN` 目录) 两个语种
- 可先润色 `scaffold/database/_fields.yaml` 里的内容，此文件会自动根据数据表的字段，添加或删掉项目


### 12. Free : “释放双手”
```sh
php artisan scaffold:free  `schema_file_name`
```
- `schema_file_name` 非必写，若不写会有提示做选择
- 先执行 `artisan scaffold:fresh` 更新缓存数据
- 生成 `mode`, `repository`, `migration` 相关文件，更新 `RepositoryServiceProvider`
- 执行 `artisan scaffold:i18` 更新多语言文件
- 询问是否执行 `artisan migrate` 创建数据表？
- **不执行** `artisan scaffold:controller` 因为需手动调整 repository 里的字段验证规则（为了生成更准确的表单控件信息）
- **不执行** `artisan scaffold:api` 因需手动调整 repository 里的字段验证规则（为了生成更准确的 api）_(PS: 要先写路由规则)_


## 文档
- schema demo [docs/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/schema_demo.yaml)
- api demo [docs/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/docs/api_demo.yaml)


## Changelog
Please see [CHANGELOG](*CHANGELOG.md*) for more information what has changed recently.


## Security
If you discover any security related issues, please email 780537@gmail.com instead of using the issue tracker.


## Thanks
- [Lamtin](https://github.com/Lamtin)


## License
The MIT License (MIT). Please see [License File](*LICENSE.md*) for more information.
 
 
