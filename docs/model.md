# Model

(未完善，待续...)

## 使用

生成 `Model` 及应对的 `Trait`

```sh
php artisan scaffold:model `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 默认生成 Trait 文件
- 添加 `-t` 重新生成 Trait 文件（若 model 存在时需要覆盖更新）
- 添加 `-f` 覆盖已存在文件
- 添加 `--factory` 同时生成 Factory 文件，并添加到 `database/seeds/DatabaseSeeder.php` 中
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`
- 添加 `--factory` 同时生成 model 对应的 factory 文件，并更新 `DatabaseSeeder`

**Example:**

```sh
php artisan scaffold:model personnels
```

## Options 设置

默认根据 `deleted_at` 设置 `options` 值

```php
trait OptionsTrait
{
    /**
     * 获取单个 model 可操作的动作
     * @return array
     */
    public function getOptionsAttribute()
    {
        $res = [];
        if ($this->deleted_at === null) {
            $res[] = ['type' => 'edit'];
            $res[] = ['type' => 'destory'];
        } else {
            $res[] = ['type' => 'force-destory'];
        }

        // 合并自定义的 options 数组
        if (method_exists($this, 'getOptions'))
        {
            $res = array_merge($res, $this->getOptions());
        }

        return $res;
    }
}
```

在 model 中新增一个指定方法 `getOptions()`

```php
    public function getOptions()
    {
        $res = [
            ['type' => 'setPassword'],
            ['type' => 'setOther'],
        ];

        return $res;
    }
```



## 模型事件

1. `retrieved`: 当数据库中检索现有模型时会触发该事件。
2. `creating`: 当创建新模型时候，会触发该事件。**备注：在创建前调用该方法**
3. `created`: 当创建新模型时候，会触发该事件。**备注：在创建后调用该方法**
4. `updating`: 当模型已经存在数据库中时，并调用了 save 方法，则会调用该事件。**备注：updating 在保存前调用**
5. `updated`: 当模型已经存在数据库中时，并调用了 save 方法，则会调用该事件。**备注：updating 在保存后调用**
6. `saving`: 当更新或者创建模型时，会调用该事件。**备注：在更新或者创建之前调用该方法**
7. `saved`: 当更新或者创建模型时，会调用该事件。**备注：在更新或者创建之后调用该方法**
8. `deleting`：当删除模式时，会调用该方法。**备注：在删除之前调用**
9. `deleted`：当删除模式时，会调用该方法。**备注：在删除之后调用**



## 扩展阅读

将所有金额相关的字段以分存储，并提供更多便捷的方法方便以各种格式展现
[model revaluation](https://github.com/overtrue/laravel-revaluation)
