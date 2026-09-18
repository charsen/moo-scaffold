---
title: 03 · 命令速查
group: Scaffold 操作手册
order: 60
---
# 03 · 命令速查

> 所有 `moo:*` 命令 + 关键 flag 一页。业务说明(何时用、产物结构)在各模块手册:[02](02-schema-codegen.md) / [05](05-api-debugger.md) / [06](06-acl.md)。

> 流水线:`moo:schema`(新建)→ 编辑 YAML → `moo:fresh`(刷缓存)→ `moo:free`(一键)或单步 `moo:model` / `moo:resource` / `moo:controller` / `moo:test` / `moo:view` / `moo:migration` / `moo:i18n` / `moo:auth` / `moo:api`。

## 流水线核心

### `moo:init "{author}"`

写 `.env` 的 `SCAFFOLD_AUTHOR`,建 `scaffold/database/` 和 `storage/scaffold/` 目录。首次安装跑一次。

### `moo:schema {name} [-f]`

新建空 schema 到 `scaffold/database/{name}.yaml`。`-f` 覆盖已有。**不支持多级目录**,schema 名 = 模块名。

### `moo:fresh [-c]`

解析 `scaffold/database/*.yaml` → 写 `storage/scaffold/`(`models.php` / `model_ids.php` / `controllers.php` / `tables.php` / `fields.php` / `enums.php`),同时增量维护 `_fields.yaml`。其中 scaffold 运行时依赖的非数据库字段会自动补进 `append_fields`，已有的项目自定义翻译保持不变。

**所有其它生成器读这份缓存,不是 YAML**。改了 schema 不跑 `moo:fresh` = 生成器看到旧数据。`-c` = 清空 `storage/scaffold/` 整目录后重建(默认增量)。

## 单个生成器

> 各生成器**详细产物 + 覆盖策略**见 [02 §谁会被覆盖](02-schema-codegen.md#谁会被覆盖谁不会)。下面只列签名 + 关键 flag。

| 命令 | 关键 flag | 说明 |
|---|---|---|
| `moo:model [schema]` | `-f` 覆盖 / `-F` Factory / `-T` TS / `-t {表key}` 单表 | 见 [02](02-schema-codegen.md) |
| `moo:resource [schema]` | `-f` 覆盖 / `-t {表key}` 单表 / `--app={端}` | `--app` 只生成一个已注册端 |
| `moo:controller [schema]` | `-f` 覆盖 / `-t {表key}` 单表 / `--app={端}` | `resource` 端把路由插到标记处；`manual` 端不自动写路由 |
| `moo:view [schema]` | `-f` 覆盖 | Vue 页面到 `config('scaffold.frontend.views')` |
| `moo:test [schema]` | `-f` 覆盖 / `--app={端}` | 每个控制器一个路由契约冒烟测到 `config('scaffold.tests.path')`;**已并入 `moo:free`** |
| `moo:migration [schema]` | `-t {表key}` 单表 | 走 designer 同一套 diff + writer;`-t` 只为该表写 migration,其它表的变更不写 |
| `moo:i18n` | — | 顺序:`moo:fresh` → 改 `_fields.yaml` → `moo:i18n` |
| `moo:auth {app}` | `-r` 显示路由 | 见 [06-acl.md](06-acl.md) |
| `moo:api {app} [ns]` | `-a` 所有 ns / `-f` 覆盖 / `-r` 显示路由 / `--stale=` 见下 | 见 [05-api-debugger.md](05-api-debugger.md) |

**`-t {表key}` 单表模式**(`moo:model` / `moo:resource` / `moo:controller` / `moo:migration` / `moo:free` 共用):只针对这一张表(yaml `tables:` 下的 key,如 `system_departments`)——代码生成器只生成该表的 Model/Resource/Controller/Request,`moo:migration` 只写该表的 migration(其它表的变更不写),同 schema 其它表跳过。配 `-f` 时尤其有用——强制覆盖只动这张表,不会误覆盖同模块其它表手改过的文件。表 key 不存在会报错并列出可选项。

**`moo:api --stale=`** 控制路由删了的 action 怎么处理。action key 格式 `{action}_{http_method}`(如 `index_get`),每次有改动在 `scaffold/api/history/` 落一份发布历史。

| 值 | 行为 |
|---|---|
| `deprecate`(默认) | 标"已弃用",留在 YAML |
| `keep` | YAML 不动 |
| `delete` | 直接从 YAML 删 |

## `moo:free {app} {schema}` — 一键编排

```bash
php artisan moo:free admin Light -a
php artisan moo:free mobi Light -a                 # 只生成 Mobi 端
php artisan moo:free admin Light -t system_departments   # 只生成单张表的代码
```

依次跑:`FreshStorageGenerator` → `CreateModelGenerator` → `CreateResourceGenerator` → `CreateControllerGenerator`(更新路由)→ `UpdateMultilingualGenerator` → `UpdateAuthorizationGenerator` → migration(走 designer diff + writer,empty diff / 加载失败 / 疑似 rename 只 warn 不阻断)→ **仅 `-a`** `CreateApiGenerator` → 问"现在 `php artisan migrate` 吗"。

适合**日常主流程**,单点修补用 `moo:adder` 或对应单个 `moo:*`。

| Flag | 说明 |
|---|---|
| `-f` | 强制覆盖 Model / Resource / Controller / Request(慎用) |
| `-a` | 加上 API YAML 生成步骤 |
| `-t {表key}` | **单表模式**:只为这张表(yaml `tables:` 下的 key,如 `system_departments`)生成 Model / Resource / Controller / Request,**且 migration 只写这张表**。配 `-f` 时尤其有用——不会误覆盖同模块其它表手改过的文件。i18n / auth / api 仍全量(聚合级,跑全量才正确)。表 key 不存在会报错并列出可选项 |

`{app}` 是必须已在 `scaffold.controller` 注册、且由当前 schema/table 的 `controller.app` 声明的单一目标端。`moo:free` 不再顺带生成其它端；目标不匹配时会在 Model/Controller 等业务代码落盘前报错。

## `moo:adder {app} {folder}` — 增量加 action

```bash
php artisan moo:adder admin Light/Book
```

给已有 controller 加一个新 action + 路由,**不动其它代码**。已上线的 controller 加新接口用这个,别跑 `moo:free`。

## 辅助命令

### `moo:account:add {username?} [--password=] [--phone=] [--role=admin|member] [--disabled] [--by=]`

创建账号到 `scaffold/accounts.yaml`。**首次部署专用**(跨过"UI 要登录、登录要账号"的鸡蛋问题),之后账号操作全走 `/scaffold/accounts`。

| Flag | 含义 |
|---|---|
| `{username?}` | 用户名,省略 → 交互 prompt |
| `--password=` | 明文密码,省略走 prompt |
| `--phone=` | 手机号(可选) |
| `--role=` | 默认 `admin`,可选 `member` |
| `--disabled` | 创建后立即禁用 |
| `--by=` | 创建人,默认 `system` |

### `moo:snapshot:init [--schema=] [--dry-run] [--force] [--no-db-check]`

designer baseline 快照初始化。`.snapshots/{Schema}.yaml` 是 designer diff 的参照系,跨设备 / 多人协同必须 commit 进 git(详 [14](14-multi-dev-workflow.md))。每 schema 首次必跑。

```bash
php artisan moo:snapshot:init                    # 全 schema(默认 skip 已存在)
php artisan moo:snapshot:init --schema=Platform  # 只处理某个
php artisan moo:snapshot:init --force            # 覆盖已存在快照
php artisan moo:snapshot:init --dry-run          # 只列会写哪些,不实际写
php artisan moo:snapshot:init --no-db-check      # 跳过 yaml↔DB 对账
```

**前置**:当前 yaml 跟 DB 一致(走过 designer / `moo:migration`),否则把未 migrate 的改动吃进 baseline → 后续 diff 漏报。落基线前自动反查活 DB(mysql `information_schema`)对账**列类型 / varchar size / 单列 unique 索引**,不符报 `⚠ drift yaml=… db=…`(只读告警,baseline 仍按当前 yaml 落)。非 mysql / DB 不可达自动跳过对账。详 [04](04-db-docs-designer.md) baseline 段。

### `moo:db:audit [--schema=]`

随手查 yaml ↔ 实际 DB 漂移(跟 `snapshot:init` 内嵌对账同源,独立好记)。

```bash
php artisan moo:db:audit                   # 查所有 schema
php artisan moo:db:audit --schema=Platform # 只查一个
```

对账三类:**列类型族**(varchar↔longtext…)、**varchar/char 长度**、**单列 unique 索引**。不查 nullable / unsigned / default / 多列索引 / app-level `unique`(故意收窄,低误报)。

- **纯只读**(只查 `information_schema`),任何环境可跑,也可核对**生产** DB。
- **退出码**:有漂移 `1`,干净 `0` — 可挂 pre-commit / CI 当闸门。
- 看到漂移:按 DB 现状改 yaml 重跑;baseline 需同步再 `moo:snapshot:init --schema=X --force`。
- 非 mysql / DB 不可达 → 打印提示、退出 `0`。

### `moo:audit:form-contract [--scope=] [--module=] [--out=] [--include-hidden] [--include-disabled] [--respect-layout] [--include-non-contract] [--include-waived] [--all]`

断言「create / edit 表单实际渲染出的控件，其 field 必须被对应 Store / Update Request 收下」。原为宿主项目（代号 H1）的 `audit:form-contract`，表单契约本身属于 scaffold，命令随契约搬入本包。

```bash
php artisan moo:audit:form-contract                          # 默认只报用户可见违规
php artisan moo:audit:form-contract --module=Finance          # 只扫一个模块
php artisan moo:audit:form-contract --all --out=/tmp/fc.csv   # 放开 hidden/disabled/layout 外/契约外附加键
```

- 被审计对象：`--scope` 下 `*/*Controller.php`（默认 `app/Admin/Controllers`，可绝对路径 + `--namespace=`），反射 `getFormWidgets` 后与同模块 Request 的 `rules()` 求差。
- **默认口径 = 只报用户可见控件**：hidden / disabled / formLayout 外的控件默认不计入违规与退出码；摘要始终给出分桶 `visible / hidden / disabled / layout-only / waived`，CSV 始终登记全部检出项（含 `Visible` / `ExcludedBy` 两列），各口径下是同一份全集。
- rules 之外、宿主经 `getFormConfig(reset:)` 附加的键由 `FormRequest::formatFormConfig` 打 `contract => false`。「标记 + 不可见」默认跳过，`--include-non-contract` / `--all` 才计入；「标记 + 可见」仍是真实缺陷，不豁免。
- **「刻意不实现」的显式声明**（注释形态，字段名自包含，注释重构不会失效）：

  ```php
  // @moo-waived system_logo: 早期精简：nullable 字段暂不实现
  // 'system_logo' => ['nullable', 'string', 'max:192'],
  ```

  命中字段归入 `waived` 桶：不计违规、不影响退出码；摘要给出条数与原因，`--include-waived` 展开逐条。**未标记**的注释规则仍按可见违规报出；**陈旧标记**（标记仍在但当期已不构成违规）以 `Stale waived markers` 警告报出，不静默忽略。
- **纯只读**（不写源码 / 不发 HTTP / 只读查表取 options），任何环境可跑。
- **退出码**：有计入的违规 `1`，干净 `0`；`waived` 永不影响退出码。
- `--out` 默认 `storage/app/audit/.audit-form-contract.csv`，**不指向仓内 baseline**。

### `moo:audit:resource-keys [--path=*] [--package=] [--limit=] [--json] [--allow=*] [--fail-on-danger]`

找出「Resource 原样透出的 json 列」里**真实数据带整数键映射**（`{1: '正常', 2: '停用'}`、`{4: 12, 6: 3}`）的地方：Laravel 的 `ConditionallyLoadsAttributes::removeMissingValues()` 会把这类数组递归 `array_values()`，前端拿到 `[2, 1]` 这种丢键形态——按值取标签错位，读回再保存把键永久写坏。

```bash
php artisan moo:audit:resource-keys                       # 扫已装私包 + 宿主 app
php artisan moo:audit:resource-keys --package=moo-attachment
php artisan moo:audit:resource-keys --limit=500 --json    # 每列抽样上限 / 机器可读
php artisan moo:audit:resource-keys --fail-on-danger      # 命中即退出码 1（CI 闸门）
php artisan moo:audit:resource-keys --allow=moo-x:FooResource:bar_meta  # 复核后接受这一处
```

- 扫描根默认取宿主 `composer.json` 的 `extra.moo-private-packages`（`vendor/<name>/src`），读不到退回 `vendor/*/*/src`，再加宿主 `app/`；`--path=/abs/src` 显式给出时**只**扫它（该根下应有 `Http/Resources` 与/或 `Models`）。
- 组合判定：**Model 的 json / array cast 列** ∩ **Resource 里 `whenHas('x')` / `$this->x` 透出的列**；命中后抽样该列真实数据（默认 200 行）递归找「键全数字且非 `0..n-1`」的层级 —— 列表与字符串键映射不算危险。
- 已声明 `public $preserveKeys = true;`（**实例**属性）的 Resource 记为「已保键」；写成 `static` 会单独提示（实例访问落到 `JsonResource::__get()` 会报错）。
- **复核后接受的命中**用 `--allow=<包>:<资源>:<列>` 显式登记（段可用 `*` 通配、可重复）：登记后不再计入危险、不影响退出码，报表标注「已豁免」；**谁都没匹配上的条目会作为「陈旧条目」告警**（豁免不烂在报表里），格式不对的条目单列 `badAllow`，不静默丢弃。豁免条目由宿主脚本或 plan 记录**理由**，命令本身不存原因。
- **纯只读**（只读查表取样，不写源码、不动数据），任何环境可跑，也可核对**生产** DB。
- **退出码**：`--fail-on-danger` 且有命中 `1`，否则 `0`；`--json` 只输出 JSON（不带横幅），便于脚本消费。
- 模型不可加载 / 抽样失败（DB 不可达、表缺失）的列计为**未能核验**并显式告警 —— 「没查到」不等于「干净」；此时 `--fail-on-danger` 的退出码只反映**已核验**的危险列，`--json` 顶层另给 `unverified` 计数。
- 收口二选一：手写 Resource 加 `$preserveKeys`（生成物上加会被 `-f` 覆盖）；或**改形状** —— 列与出参不给整数键映射，转成 `[{key|value, label}]` 列表（前端与校验同口径，最耐久）。

> 触发要同时满足两件事：该映射**经 Resource 出参**，且有人**按值取标签**或**读回再保存**。只满足前者，那一次响应丢键，数据没坏；两件都满足才是真缺陷。

### `moo:audit:former-types [--spa=] [--json]`

断言「后端 scaffold 的控件类型清单」与「下游 admin SPA `former/config.ts` 的类型注册表」是**同一个集合**：后端 `FormWidgetTypes::FORMER` 是表单契约**可能下发**的 type 全集（也是 mini-app 等动态类型登记的白名单），前端 `elComponents` 是把 type 映射到渲染组件的唯一位置。两侧此前只靠注释「人工对齐」——漏一边就是「后端下发新 type、前端静默走只读兜底」或「前端注册了后端永不下发的死类型」。

```bash
php artisan moo:audit:former-types --spa=/path/to/host-frontend                  # 给仓根目录（按约定位置探测 config.ts）
php artisan moo:audit:former-types --spa=/path/to/apps/admin/src/components/former/config.ts
php artisan moo:audit:former-types --spa=... --json                              # 机器可读
```

- `--spa=` **必填**：指向 SPA 仓根目录（按 `apps/admin/src/components/former/config.ts` / `src/components/former/config.ts` 约定位置探测）或直接指向 `config.ts`；不给、路径不存在、读不到或解析不出 `elComponents` 都报明确错误并非 0 退出 —— 「读不到」不等于「一致」。
- 比较口径：后端取运行时类常量 `Mooeen\Scaffold\Support\FormWidgetTypes::FORMER`；前端取 `config.ts` 里 `elComponents` 对象字面量的**顶层键**；只比**集合**不比顺序（声明顺序不构成渲染语义）。
- 两侧类型名目前逐字同名，因此没有别名映射；命令内保留 `ALIASES` 显式映射位（前端名 → 后端名），命名体系真的分叉时才登记，**不做**大小写 / 连字符之类的模糊归一。
- 输出两边数量、**只在前端有的**、**只在后端有的**（有别名映射时附映射表）；`--json` 只输出 JSON（不带横幅），字段 `consistent` / `backend` / `frontend` / `front_only` / `backend_only` / `aliases`。
- **纯只读**（不写文件、不跑前端构建、不依赖 DB），任何环境可跑。
- **退出码**：一致 `0` / 不一致 `1`（可当 CI 闸门）/ 用法或读取失败 `2`。
- 收口：改一侧让集合一致 —— 后端改 `Support\FormWidgetTypes::FORMER`，前端改 `former/config.ts` 的 `elComponents`；新 type 必须在前端注册，否则渲染成只读兜底。

### `moo:composer:docs [--root=] [--format=table|matrix] [--bare] [--write] [--check] [--file=]`

按宿主三份 manifest(`composer.json` / `composer.test.json` / `composer.production.json`)生成「私包清单表」,替代各 Host 手抄 `PRIVATE-COMPOSER-PACKAGES.md`(记数 / 版本 / 来源容易抄错)。

```bash
php artisan moo:composer:docs --root=/path/to/host                 # 打印 8 列清单表 + 公开包小表
php artisan moo:composer:docs --root=/path/to/host --format=matrix # 每包三档约束对照
php artisan moo:composer:docs --root=/path/to/host --bare          # 只出表格本体(嵌入宿主已有小节)
php artisan moo:composer:docs --root=/path/to/host --check         # 文档是否过期(过期非 0)
php artisan moo:composer:docs --root=/path/to/host --write         # 只替换 marker 区间
```

- `--root` 默认 `base_path()` 的上一级(含 `engine/` 的宿主根);私包顺序严格跟 `extra.moo-private-packages`,URL 取 test / production 的 `repositories.<repo-key>.url`(两者不同标 `⚠ 冲突`)。
- 不在 `extra.moo-private-packages`、但以 `charsen/` 开头的 require 归入「走 Packagist 的公开包」小表(例:`charsen/moo-feedback`)。
- `--bare` 只输出 Markdown 表格本体(表头 + 分隔行 + 数据行),不带 `### 标题`、计数说明与装饰空行;公开包小表仍出表体但不带标题 —— 便于嵌进各 Host 已有的 `## 本仓私包清单` 小节。`--bare --write` 时 marker 区间内只写裸表。
- `--write` / `--check` 走 marker 区间 `<!-- BEGIN moo-manifest-table -->` / `<!-- END moo-manifest-table -->`;文档没有 marker 时 `--write` **拒绝**(不猜插入位置),`--check` 视为失败。
- **纯只读**(`table` / `matrix` / `--bare` / `--check`),任何环境可跑。

### `moo:cloud:*` — 云端

| 命令 | 作用 |
|---|---|
| `moo:cloud:push [--type] ...` | 把本地 runtime / 慢 SQL 推送到 moo-scaffold-cloud(推后回收;由 moo-monitor-laravel 提供,命令名不变) |
| `moo:cloud:mcp` | MCP server:把云端 runtime 错误与待办暴露给 AI(拉取 / 认领 / 处理 / 回写,共六个工具) |
| `moo:monitor:migrate [--dry-run]` | 从旧布局迁移:平移本地 yaml / 游标到 storage/moo-monitor、.env 改名体检(由 moo-monitor-laravel 提供) |

详 [16-cloud-push.md](16-cloud-push.md)。

### `moo:scaffold:merge-yaml {file} [--dry-run]`

git 同步冲突的 YAML 自动合并器(多端同步脚本在 rebase 冲突时调它;同步脚本由下游维护,见 [11-sync.md](11-sync.md))。手动测:

```bash
php artisan moo:scaffold:merge-yaml scaffold/accounts.yaml --dry-run
```

详 [11-sync.md](11-sync.md) §4。

## 通用注意事项

- **大部分** `moo:*` 受 `config('scaffold.only_in_local')`(默认 `true`)控制,**非 local 环境直接退出**。设计意图,不要绕开。
- **例外清单**(`$requiresLocalEnvironment = false`,prod 可跑,多是 cron / 运维):
  - `moo:fresh` — 缓存刷新
  - `moo:account:add` — 首部署 bootstrap
  - `moo:scaffold:merge-yaml` — git sync 冲突合并
  - `moo:db:audit` — 只读对账(也核对生产 DB)
  - `moo:audit:form-contract` — 只读表单契约审计(默认只报用户可见违规)
  - `moo:audit:resource-keys` — 只读 Resource 整数键映射体检(抽样核对该列真实数据)
  - `moo:audit:former-types` — 只读跨仓体检(SPA `former/config.ts` 类型注册表 ↔ 后端 `FormWidgetTypes::FORMER`)
  - `moo:composer:docs` — 只读体检(宿主私包清单文档 ↔ 三份 manifest)
  - `moo:cloud:push` / `moo:cloud:mcp` / `moo:monitor:migrate` — 云端推送 / MCP / 旧版迁移(由 moo-monitor-laravel 提供,无 only_in_local 限制)
- 改了 schema YAML **务必**先 `moo:fresh`。
- 生成的 `Traits/*ModelTrait.php` / `Enums/*.php` 每次都被覆盖，**别写业务代码**；`HasOperator` 等通用能力直接引用共享 `Mooeen\Scaffold\Concerns\*`，不生成本地副本。
- `moo:auth` / `moo:api` 只识别 controller 中真实定义过的方法。
- `moo:schema` **不支持多级目录**。
- `moo:controller` 依赖 `:insert_code_here:do_not_delete` 标记,标记被删 = 路由插不进去。
