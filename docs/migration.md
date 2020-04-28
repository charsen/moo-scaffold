# migration

(未完善，待续...)

## 使用

创建数据迁移文件，一个数据表对应一个 `migration`。

```sh
php artisan scaffold:migration `schema_file_name`
```

- `schema_file_name` 非必写，若不写会有提示做选择
- 添加 `-m` 会执行 `php artisan migrate`
- 添加 `--fresh` 刷新缓存数据，会先执行 `artisan scaffold:fresh`



::: warning

字段名称不能以 `_txt` 结尾，以免在列表返回 `columns`  是误处理了；

详见 `app/Http/Resources/TableColumsCollection.php` 的 `toArray()` 函数

:::
