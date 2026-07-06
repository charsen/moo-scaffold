# Scaffold YAML 风格约定

> 无论是 designer GUI 还是人工编写,yaml 都该长成同一个样子——这份约定就是那个"样子"。GUI save 走 `src/Designer/YamlFormatter::dumpPreservingComments()`,只要人工也照此写,两边落盘天然一致。

**前置阅读**:本文只讲"风格"(格式怎么写才不会被 GUI 重排)和 `unique` 双语义,不教怎么写 schema。schema 文件放在宿主项目 `scaffold/database/*.yaml`,完整骨架、字段可选项、`新建 → moo:fresh → moo:free` 工作流(包括"改了 YAML 必先 `moo:fresh`"这个最高频坑)见 [`guide/02-schema-codegen.md`](guide/02-schema-codegen.md),完整可运行样例见 [`schema_demo.yaml`](schema_demo.yaml)。

**两个术语**(下文反复出现):

- **designer GUI** = 宿主项目里的数据库设计器,浏览器访问 `/scaffold/db/designer/{Module}`(详见 [`guide/04-db-docs-designer.md`](guide/04-db-docs-designer.md))。下文说"GUI save"指在设计器里点保存,落盘经 `YamlFormatter`。
- **config-ui** = `/scaffold/config` 下的配置可视化编辑,同样通过 yaml dump 写盘。

---

## 一、为什么有这份约定

scaffold 的多数 yaml 改动来自 GUI(designer / config-ui 等)。Symfony 的 `Yaml::dump` 不保留 yaml 注释,部分引号风格也会被它重排。如果人工写法和 GUI dump 的风格各走各的,git diff 就会被一堆无关的格式变更淹没,"GUI save 又把我的 yaml 弄乱了"的体感由此而来。

让人工和 GUI 按同一个模子写,这种体感就从根上消失。`YamlFormatter` 是 `SchemaLoader::saveModule` 唯一的 dumper,约定一次定死,落盘永远一致。

---

## 二、约定细则

### 2.1 注释:走 yaml 字段,不用行尾 `#`

`YamlFormatter` 内置了注释保留算法,但**某行末尾的 `# 注释` 形式**仍会丢——它在 `Yaml::parse` 阶段就被当成噪声解析掉了。

避免方式:语义注释走 yaml 字段。

```yaml
# ❌ 不要这样
status_code: { type: tinyint, default: 1 }  # 1: 未处理, 2: 处理中, 3: 已完成

# ✅ 这样写
status_code: { type: tinyint, default: 1, desc: '{1: 未处理, 2: 处理中, 3: 已完成}' }
```

可用 yaml 字段:
- 字段级:`desc`(枚举/语义说明)、`comment`(数据库注释)
- 表级:`attrs.desc`(表说明)、`attrs.remark`(多行备注数组)
- 模块级:`module.desc`

**`desc` 和 `enums:` 块的关系**:`desc` 的值是个被引号包住的**字符串**(故意不写成真 map),只是给人看的速记;真正驱动代码生成的是表级 `enums:` 块——它生成 `Enums/FieldName.php`、可在 Model 里 `cast`(见 [`guide/02`](guide/02-schema-codegen.md))。两者不互斥,`schema_demo.yaml` 里常常同时出现:`desc` 是字段旁注,`enums` 是机器可读定义。

### 2.2 文件头部 / 段间注释:**自动保留**

文件头部连续的 `#` 块(版权 / 作者 / 日期),以及紧贴在某个 yaml key 之前的 `#` 块,`YamlFormatter` 都会自动保留并挂回原位,无需手动挪。

```yaml
###
# Platform
# @author Charsen
##
module:
    name: 平台管理
    folder: Platform
tables:
    # added: 2025-08-28
    platform_banners:
        ...
```

(注意层级:`module:` 与 `tables:` 是平级的顶层 key,表名挂在 `tables:` 之下缩进 4 空格——对照 [`schema_demo.yaml`](schema_demo.yaml) 第 7-11 行。)

段间注释靠"紧跟其后的那行 yaml 内容"作 anchor 挂回原位。已知边界:若 anchor 行的内容在文件里出现多次(比如多张表都有一模一样的 `id: {}` 行),注释只会挂回**首次出现处**——实际几乎碰不到。实现细节见 `src/Designer/YamlFormatter.php` 中 `reattachComments()` 的方法注释。

### 2.3 字段格式:单行 `{...}`

所有字段定义都用单行 `{a: 1, b: 2}` 形式,不要写成多行 block:

```yaml
# ✅ 推荐
region_name: { name: 地区名称, type: varchar, size: 96 }

# ❌ 不推荐(GUI dump 不会产生这种风格)
region_name:
    name: 地区名称
    type: varchar
    size: 96
```

原理:`YamlFormatter` 用 `Yaml::dump($data, 4, 4, DUMP_OBJECT_AS_MAP)`,第二个参数 `inline=4`(常量 `YamlFormatter::DUMP_INLINE`)的含义是——**嵌套到第 4 层起改用单行 flow 风格**(`{...}` / `[...]`),之前的层级保持多行 block。按 0 起算数层级:顶层 `module:` / `tables:` 是第 0 层,表名第 1 层,`fields:` 第 2 层,字段名第 3 层,字段名的值(属性 map)正好落在第 4 层 → 被压成单行。人工写成 block,GUI dump 后也会被改回单行。

### 2.4 enums 块:可压扁(接受)

同一个 `inline=4` 机制作用在 enums 上:`enums:` 第 2 层,枚举字段名第 3 层,它的值(整组枚举项)在第 4 层 → GUI dump 会把每个枚举字段压成一行:

```yaml
# 人工写(常见)
enums:
    page_type:
        web: [1, Web, 网站]
        app: [2, App, App]

# GUI dump 后
enums:
    page_type: { web: [1, Web, 网站], app: [2, App, App] }
```

数据语义等价,**接受这个 trade-off**。觉得压扁难读,理论上可以在 `YamlFormatter::dump()` 里加一步逐行文本后处理把 enums 强制展开(类似现有的 `insertBlankLinesBetweenTables()` 那种按行处理)——目前判断不值得,不做。

### 2.5 quote 风格:省略冗余引号

`Yaml::dump` 会去掉不必要的引号,人工写也照此:

```yaml
# ✅
app: [admin]

# ❌(会被 GUI dump 改成上面的形态)
app: [ 'admin' ]
```

字符串里含特殊字符(`:` `#` `{` 等)时该加的引号,yaml 自己会处理。

注意"冗余"以 dump 结果为准——**有些引号是有语义的,去掉会改数据**。典型:`default: 'null'` 是字符串 `"null"`,去掉引号变成真正的空值 NULL,所以 `Yaml::dump` 会保留这个引号,人工也不要手痒去掉。

### 2.6 表级 key 顺序:GUI 会强制重排

`YamlFormatter::canonicalizeShape()` 把每张表的 key 归一成固定顺序:

```
model → controller → attrs → index → fields → enums
```

`attrs` 子 key 同样归一:

```
name → desc → remark → prefix → created_by → created_at → updated_by → updated_at
```

未列出的 key 落到末尾(保留相对顺序,不丢)。人工按别的顺序写,GUI save 一次就全部重排、产生一片无关 diff——这正是本约定要消除的问题,所以**手写时直接按 canonical 顺序排**。

### 2.7 tables 间空行:GUI 会强制补

`insertBlankLinesBetweenTables()` 在相邻两张表之间插一行空行(第一张表紧贴 `tables:` 不插)。人工写时不留空行,GUI save 会补上——手写时照样留。

### 2.8 字段属性 key 是白名单制

字段定义里只认这些 key(`SchemaLoader::FIELD_LEGAL_KEYS`):

```
name / type / size / min_size / required / unique / db_unique /
default / unsigned / desc / comment / index / precision / format
```

未知 key 会被 `sanitizeFieldAttrs()` warn(疑似笔误时附建议,如"未知属性 `requried`,是 `required` 的笔误?")并**忽略**。手写前对照这份清单,别发明字段。

---

## 三、字段 `unique` 语义

yaml 里 `unique` 有 app 级和 DB 级两种含义,别混。

### 3.1 两种写法 vs 行为

| yaml 写法 | 含义 | Request 验证 | Migration | 推荐用于 |
|---|---|---|---|---|
| `unique: true` | **app-level + 软删感知**(即考虑 `deleted_at`)| `Rule::unique()->whereNull('deleted_at')`(若表有 deleted_at 列)| **不** emit DB unique 约束 | 业务表带 `deleted_at`,允许软删后同名记录复活 |
| `db_unique: true` | **DB-level 强约束** | `Rule::unique()` Laravel 默认(跨软删唯一)| emit `$table->unique()` | 系统级 token / 业务 ID / 跨软删都必唯一 |
| 索引块 `type: unique`(verbose)| 等价 `db_unique: true` | 同上 | 同上 | 显式声明 verbose 形式 |
| 索引块 `type: index` | 普通 index | 无验证 | emit `$table->index()` | 仅用于查询优化 |

**索引块(`index:`)的实际写法**(verbose 形式;对照 `schema_demo.yaml` 第 21-25 行):

```yaml
index:
    id: { type: primary, fields: id }
    parent_id: { type: index, fields: parent_id }
    department_name: { type: unique, fields: department_name }
```

`fields` 可以是单字段,也可以是数组(复合索引,如 `UNIQUE KEY (a, b)`,在 GUI 右主区"索引"卡片编辑——见 [`guide/04`](guide/04-db-docs-designer.md))。

### 3.2 designer GUI 选项

字段索引 dropdown 下拉:

```
—                           ← 不建索引、不加唯一约束(默认项)
primary
unique(app · 软删过滤)    ← 对应 yaml `unique: true`
unique(DB · 强约束)        ← 对应 yaml index 块 `type: unique`(或用 `db_unique: true` 简写)
index
```

### 3.3 兼容性承诺

- **旧 yaml 里的 `unique: true` 无需改动** — 按 app-level 解释,Request 验证软删感知,Migration 不 emit DB unique。
- **历史遗留的同时有两种写法**(attr.unique=true + index 块 type:unique 同存):GUI dropdown 优先显示 `unique(DB · 强约束)`,想改 app 显式选择即可。
- **想要 DB 强约束**:yaml 显式 `db_unique: true`,或在 GUI 选 `unique(DB · 强约束)`。

### 3.4 典型例子

```yaml
# 业务表:小区名称,允许软删后重建同名(app-level)
platform_residences:
    fields:
        residence_name: { type: varchar, size: 192, unique: true, name: 小区名称 }
    # ↑ index 块里**不**该再有 residence_name,否则会被识别成 DB 强约束
```

token / 业务 ID 这类跨软删都必唯一的字段,改用 `db_unique: true`——`SchemaLoader::promoteInlineUnique()` 会在 load 时自动把它派生进索引块(`type: unique`),生成 migration 时 emit `$table->unique()`。

**注意:手写的 `db_unique: true` 落盘后会"消失"** — 上面这步派生同时会把 `db_unique` 这个简写 key 从字段属性里去掉(index 块单源,简写不回写;见 `SchemaLoader::saveModule` 注释)。所以经过一次 GUI save 后,yaml 里只剩 index 块的 `type: unique`,字段上看不到 `db_unique` 了——这是预期行为,不是 GUI 吃了你的改动,语义没变。

### 3.5 app-level unique 跨软删生效

`Foundation/FormRequest::getUnique()` 用 `Rule::unique()` builder,自动检测 `deleted_at` 列并加 `whereNull('deleted_at')`,于是 app-level unique 在软删后允许同名记录复活。

这个行为在**包基类运行时**生效:既有 Request 文件里的 `$this->getUnique(...)` 调用形态不变,升级包即生效,**不需要重新生成任何文件**。

只有当你**改了 yaml 里的 unique / db_unique 语义**时才需要两步:先 `php artisan moo:fresh` 刷 `storage/scaffold` 缓存(它只刷缓存,不生成文件),再跑 `php artisan moo:controller`(或 `moo:free` 流水线)重新生成 Request 文件。

---

## 附:批量 normalize(默认不需要)

Designer save 每次都走 `YamlFormatter`,落盘风格永远跟本约定一致,**日常没有"手动批量 normalize"的场景**。

只有 import 外部老 yaml(从别处拷来 / 历史快照恢复 / 别的工具产的)想一次性对齐时,自己跑一段一次性脚本(`php artisan tinker` 里贴进去即可):

```php
use Mooeen\Scaffold\Designer\YamlFormatter;
use Symfony\Component\Yaml\Yaml;

foreach (glob(base_path('scaffold/database/*.yaml')) as $path) {
    $original = file_get_contents($path);
    $data     = Yaml::parse($original);
    file_put_contents($path, YamlFormatter::dumpPreservingComments($data, $original));
}
```

跑前确保 `git status` clean,跑完 git diff 自己 review。(`tools/format-scaffold-yaml.sh` 只是个占位说明壳,`exit 0` 空跑,没有可执行逻辑。)

没有 `moo:format-yaml` 批量命令:GUI dump 跟手写已对齐,批量 normalize 的使命已完;备份/快照类工具能走 git 就走 git。
