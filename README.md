# Laravel Scaffold

## 安装
通过 [composer](https://laravel-china.org/composer) 安装
```bash
composer require --dev charsen/laravel-scaffold
```

- (可选)发布配置文件到，若需要调整配置的话：
```bash
php artisan vendor:publish --provider=Charsen\\Scaffold\\ScaffoldProvider --tag=config
```
将会发布 `scaffold.php` 到 `config` 目录下.

- 发布前端公共资源包到 public 目录下：
```bash
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
添加 `-f` 覆盖已存在文件

### 3. 刷新/生成 schema 数据缓存
```sh
php artisan scaffold:fresh
```
添加 `-c` 清空数据后重建

### 4. 查看数据库文档
```sh
http://{{url}}}/scaffold/db
```

### 5. 创建数据迁移文件
- `schema_file_name` 非必写，若不写会有提示做选择
```sh
php artisan scaffold:migration `schema_file_name`
```
添加 `-f` 覆盖已存在文件

### 6. 创建模型文件
- `schema_file_name` 非必写，若不写会有提示做选择
```sh
php artisan scaffold:model `schema_file_name`
```
- 添加 `-f` 覆盖已存在文件
- 添加 `-c` 同时生成 controller
- 添加 `-r` 同时生成 repository
- 添加 `-m` 同时生成 migration (程序会确认，是否执行 `artisan migrate`)

### 7. 创建资源仓库文件 
- `schema_file_name` 非必写，若不写会有提示做选择
- 类里的验证规则是生成 api 时的参数来源
```sh
php artisan scaffold:repository `schema_file_name`
```
添加 `-f` 覆盖已存在文件

### 8. 创建控制器
- `schema_file_name` 非必写，若不写会有提示做选择
- 类里的 action 是生成 api 的依据，一个 action 一个 api 
```sh
php artisan scaffold:controller `schema_file_name`
```

### 9. 生成接口配置文件
- `namesapce` 非必写，若不写会有提示做选择，
- 自动获取 `namesapce` 的选择提示内容，只支持 Http/Controllers/ 再往下两级，更深的层级不支持!!!
- 要先设置好路由规则，通过  Route::getRoutes() 知道有多少api（但有功能用了 resources 规则，实际没那么多）
- 用路由与控制器的 action 求交集，得出真实的 api
- 默认是附加新的 action 到对应的配置文件，若减少了会有提醒，需要人工删除 
```sh
# namespace = app/controllers 下的某个目录，或多级目录
php artisan scaffold:api `namesapce`
```
添加 `-f` 覆盖已存在文件

### 10. 查看接口文档
```
http://{{url}}}/scaffold/api
```

### 11. 更新 i18n 文件
- 所有的数据表字段，除了 id, created_at, updated_at, deleted_at 以外
- 所有 model 里定义的数据字典
```sh
php artisan scaffold:i18n
```

### 12. Free : “释放双手”
- 先执行 `artisan scaffold:fresh` 更新缓存数据
- 同时生成 controller, mode, repository, migration
- 执行 `artisan scaffold:i18` 更新多语言文件
- 执行 `artisan migrate` 
```sh
php artisan scaffold:free
```

## 文档
- schema demo [document/schema_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/document/schema_demo.yaml)
- api demo [document/api_demo.yaml](https://github.com/charsen/laravel-scaffold/blob/master/document/api_demo.yaml)

## Changelog
Please see [CHANGELOG](*CHANGELOG.md*) for more information what has changed recently.

## Security
If you discover any security related issues, please email 780537@gmail.com instead of using the issue tracker.

## Thanks
- [Lamtim](https://github.com/Lamtin)

## License
The MIT License (MIT). Please see [License File](*LICENSE.md*) for more information.
 
 
