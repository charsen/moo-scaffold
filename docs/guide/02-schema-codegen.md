# 02 · Schema 与代码生成

> Schema YAML 是模块的唯一事实源,`moo:*` 生成器照它派生 Model / Resource / Controller。命令清单查 [03-cli-reference.md](03-cli-reference.md)。

> 关节:生成器读的不是 YAML,是 `moo:fresh` 把 YAML 嚼成的缓存(`storage/scaffold/*.php`)。**改了 YAML 必先 `moo:fresh`**,否则生成器看到的是旧缓存——最高频的坑。

## 工作流

```bash
# 1. 新建 / 编辑 schema(字段所有可选项见 docs/schema_demo.yaml)
php artisan moo:schema Light
vim scaffold/database/Light.yaml
# 2. 刷缓存(忘了这步 = 白改)
php artisan moo:fresh
# 3.(可选)润色字段中文 → 跑 i18n
vim scaffold/database/_fields.yaml && php artisan moo:i18n
# 4. 一键生成整套代码
php artisan moo:free admin Light -a
```

随后在 Model / Filter / Controller 业务方法里手工补逻辑。验证:`/scaffold/db/designer/{Module}` 看设计器、`/scaffold/dictionaries` 看字典、`/scaffold/api/request` 调接口。

## Schema YAML 结构

完整可运行样例见 [`../schema_demo.yaml`](../schema_demo.yaml)。骨架:

```yaml
module:
    name: 系统管理          # 模块中文名(进 i18n / 文档)
    folder: System          # 命名空间 / 目录名

tables:
    system_departments:
        model:      { class: Department }
        controller: { app: ['admin', 'api'], class: DepartmentController }
        attrs:      { name: 部门, desc: 描述... }
        index:      { id: { type: primary, fields: id } }
        fields:
            id: {}                                          # 用 _fields.yaml 默认定义
            department_name: { unique: true, type: varchar, size: '2,128' }
            created_at: {}
        enums:
            department_type: { head_office: [1, head office, 总公司] }
```

四个易踩点:

- **`id: {}`** 空对象 = 用 `_fields.yaml` 的默认定义。`_fields.yaml` 由 `moo:fresh` 增量维护,字段中文名集中润色一次,所有 schema 共享。
- **`controller.app`** 是数组,同一张表的 controller 可同时落在 `admin` 和 `api` 下。
- **`enums`** 写在表里,生成 `Enums/FieldName.php`,可在 Model 里 `cast`。
- **`size: '2,128'`** 形如 `'最小,最大'`,同时作用于 DB 列长度 + Request 校验。

## `moo:free` 编排的流水线

`php artisan moo:free admin Light -a` 内部依次:

1. **刷缓存** — `FreshStorageGenerator`(等价 `moo:fresh`)。
2. **生成代码** — `CreateModelGenerator` → `CreateResourceGenerator` → `CreateControllerGenerator`(往 `routes/{app}.php` 的 `:insert_code_here:do_not_delete` 标记插路由)→ `UpdateMultilingualGenerator`(多语言)→ `UpdateAuthorizationGenerator`(ACL,见 [06-acl.md](06-acl.md))。
3. **迁移 + 可选** — `SchemaDiffService` + `MigrationWriter` 出 migration(容错不阻断:empty diff / 加载失败 / 疑似 rename 只 warn);带 `-a` 再补 `CreateApiGenerator`(API YAML,见 [05-api-debugger.md](05-api-debugger.md));最后问一句"现在 `php artisan migrate` 吗"。

> 只想动一处别跑全量:加单个 action 用 `moo:adder`,补某一类文件单跑对应 `moo:*`。

## 谁会被覆盖,谁不会

元规则:**默认跳过已存在的业务文件,`-f` 才覆盖**。两个例外每次强制覆盖,不吃 `-f`。

> **铁律**:`Traits/*ModelTrait.php` 和 `Enums/*.php` 是 schema 的静态镜像,改它们 = 改"下次生成必被抹掉"的代码。要扩展行为去 Model / Filter 里写。

| 文件 | 默认行为 | `-f` | 说明 |
|---|---|---|---|
| `Model.php` / `ModelFilter.php` | 跳过 | 覆盖 | 写业务逻辑 |
| `Traits/*ModelTrait.php` | **强制覆盖** | — | schema 投影,别写业务 |
| `Enums/*.php` | **强制覆盖** | — | schema 投影,别写业务 |
| `Controller.php` / `Request.php` / `Resource.php` | 跳过 | 覆盖 | 写 action / 校验 / 返回字段 |
| `Vue 页面.vue` | 跳过 | 覆盖 | 写前端 |
| `ModelFactory.php`(仅 `-F`)/ `Model.ts`(仅 `-T`) | 跳过 | 覆盖 | 假数据 / TS 类型 |
| migration 文件 | 每次新生成 | — | 时间戳唯一 |

## 缓存文件速查

`moo:fresh` 产出的 `storage/scaffold/*.php`,**全都别手改**,每次刷新都会重写:

| 文件 | 内容 |
|---|---|
| `models.php` | 表 → 模型类的映射 |
| `model_ids.php` | 模型短 ID(给前端 / API key 用) |
| `controllers.php` | 表 → 控制器类的映射(分 app) |
| `tables.php` | 表的所有元信息(字段 / 索引 / 枚举 / 注释) |
| `fields.php` | 全局字段词典(`_fields.yaml` 解析后) |
| `enums.php` | 全局枚举词典 |

## 常见踩坑

| 现象 | 真因 |
|---|---|
| 改 schema 后生成器没反应 | 忘跑 `moo:fresh`(生成器读缓存不读 YAML) |
| 路由没插进去 | `routes/{app}.php` 的 `:insert_code_here:do_not_delete` 标记被删了 |
| `moo:model -F` 没追加到 Seeder | `DatabaseSeeder.php` 的 `//:auto_insert_code_here::do_not_delete` 标记被删了 |
| Trait 里的改动消失了 | Trait 每次都被覆盖,业务代码搬到 Model |
| `moo:api` 没扫到接口 | 接口必须**真实存在于 controller 的 `public function`** 且**有路由指向** |
| `moo:schema Foo/Bar` 报错 | 不支持多级目录,schema 文件名必须是单一标识符 |
