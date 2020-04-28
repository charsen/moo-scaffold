# Auth 权限验证

(未完善，待续...)

维护一处注释，生成 actions 相关文件，及多语言文件。

## 更新Authorization 文件

```sh
php artisan scaffold:auth
```

- 更新 `./resources/lang/{en, zh-CN}/actions.php`
- 更新 `./app/config/actions.php`

## Controller 类注释定义

PS: 以下注释使用本工具时会自动生成。

```php

/**
 * 人员控制器
 *
 * @package_name {zh-CN: 后台管理 | en: Admin}
 * @module_name {zh-CN: 人事管理 | en: Personnels}
 * @controller_name {zh-CN: 人员管理 | en: Management Personnel}
 *
 * @package App\Http\Controllers\Personnels;
 * @author  Charsen <https://github.com/charsen>
 * @date    2019-08-20 20:39:48
 */
class PersonnelController extends Controller
{
    //...

}
```

## action 方法注释定义

PS: 以下注释使用本工具时会自动生成。

```php
    // ...

    /**
    * 列表
    *
    * @acl {zh-CN: 人员列表 | en: Personnel List}
    *
    * @param  \App\Http\Requests\Personnels\PersonnelRequest  $request
    * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    */
    public function index(PersonnelRequest $request)
    {
        //...

    }

    // ...
```

PS: 需要做授权的 `action` 必须在注释中写 `@acl {zh-CN: 中文 | en: English}` 否则会被加入白名单。

::: warging
当前生成机制限制了 Controllers 下的层级，只支持 `app/Http/Controllers/` 往下两级目录！
:::
