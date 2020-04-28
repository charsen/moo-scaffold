# Controller

(未完善，待续...)

## 使用

生成 `Controller` 文件，默认包含以下 `action`

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

```sh
php artisan scaffold:controller `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-f` 覆盖已存在文件（Request 文件不会被覆盖，需要手动删除）
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 同时会生成对应的 `From Request` 对象于 `app/Http/Requests/` 路径下（目录层次与 Controller 的一致）

::: tip !!! PS !!!

- `controller` 里的 `action` 是生成 `接口文档及调试` 的依据，`one action == one api`
- 请先 **认真** 设置 `From Request` 里的验证规则，因为类里的验证规则是生成 `api` 及 `表单控件` 时的数据来源

:::

## 对应的 FormRequest 对象

`scaffold:controleer` 时会同步生成 `FormRequest`，一个 action 对应一个 actionRules。

## action 注释与权限控制

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

::: warning 注意
注：若在 controller 头部不写这块注释代码，执行 `scaffold:auth` 时不会生成到 `actions.php` 中。
:::
