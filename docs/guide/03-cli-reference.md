# 03 · 命令速查

> 所有 `moo:*` 命令 + 关键 flag 一页。业务说明(何时用、产物结构)在各模块手册:[02](02-schema-codegen.md) / [05](05-api-debugger.md) / [06](06-acl.md)。

> 流水线:`moo:schema`(新建)→ 编辑 YAML → `moo:fresh`(刷缓存)→ `moo:free`(一键)或单步 `moo:model` / `moo:resource` / `moo:controller` / `moo:test` / `moo:view` / `moo:migration` / `moo:i18n` / `moo:auth` / `moo:api`。

## 流水线核心

### `moo:init "{author}"`

写 `.env` 的 `SCAFFOLD_AUTHOR`,建 `scaffold/database/` 和 `storage/scaffold/` 目录。首次安装跑一次。

### `moo:schema {name} [-f]`

新建空 schema 到 `scaffold/database/{name}.yaml`。`-f` 覆盖已有。**不支持多级目录**,schema 名 = 模块名。

### `moo:fresh [-c]`

解析 `scaffold/database/*.yaml` → 写 `storage/scaffold/`(`models.php` / `model_ids.php` / `controllers.php` / `tables.php` / `fields.php` / `enums.php`),同时增量维护 `_fields.yaml`。

**所有其它生成器读这份缓存,不是 YAML**。改了 schema 不跑 `moo:fresh` = 生成器看到旧数据。`-c` = 清空 `storage/scaffold/` 整目录后重建(默认增量)。

## 单个生成器

> 各生成器**详细产物 + 覆盖策略**见 [02 §谁会被覆盖](02-schema-codegen.md#谁会被覆盖谁不会)。下面只列签名 + 关键 flag。

| 命令 | 关键 flag | 说明 |
|---|---|---|
| `moo:model [schema]` | `-f` 覆盖 / `-F` Factory / `-T` TS / `-t {表key}` 单表 | 见 [02](02-schema-codegen.md) |
| `moo:resource [schema]` | `-f` 覆盖 / `-t {表key}` 单表 | — |
| `moo:controller [schema]` | `-f` 覆盖 / `-t {表key}` 单表 | 路由插到 `:insert_code_here:do_not_delete` 标记处,标记被删 = 插不进 |
| `moo:view [schema]` | `-f` 覆盖 | Vue 页面到 `config('scaffold.frontend.views')` |
| `moo:test [schema]` | `-f` 覆盖 | 每个控制器一个路由契约冒烟测(Pest,B-lean:验路由插对 + 控制器能加载,不碰 DB/auth)到 `config('scaffold.tests.path')`;**已并入 `moo:free`** |
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
php artisan moo:free admin Light -t system_departments   # 只生成单张表的代码
```

依次跑:`FreshStorageGenerator` → `CreateModelGenerator` → `CreateResourceGenerator` → `CreateControllerGenerator`(更新路由)→ `UpdateMultilingualGenerator` → `UpdateAuthorizationGenerator` → migration(走 designer diff + writer,empty diff / 加载失败 / 疑似 rename 只 warn 不阻断)→ **仅 `-a`** `CreateApiGenerator` → 问"现在 `php artisan migrate` 吗"。

适合**日常主流程**,单点修补用 `moo:adder` 或对应单个 `moo:*`。

| Flag | 说明 |
|---|---|
| `-f` | 强制覆盖 Model / Resource / Controller / Request(慎用) |
| `-a` | 加上 API YAML 生成步骤 |
| `-t {表key}` | **单表模式**:只为这张表(yaml `tables:` 下的 key,如 `system_departments`)生成 Model / Resource / Controller / Request,**且 migration 只写这张表**。配 `-f` 时尤其有用——不会误覆盖同模块其它表手改过的文件。i18n / auth / api 仍全量(聚合级,跑全量才正确)。表 key 不存在会报错并列出可选项 |

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
  - `moo:cloud:push` / `moo:cloud:mcp` / `moo:monitor:migrate` — 云端推送 / MCP / 旧版迁移(由 moo-monitor-laravel 提供,无 only_in_local 限制)
- 改了 schema YAML **务必**先 `moo:fresh`。
- 生成的 `Traits/*ModelTrait.php` / `Enums/*.php` 每次都被覆盖,**别写业务代码**;共享 `Mooeen\Scaffold\Concerns\*` 和缺失时才创建的 `HasOperator.php` 不在此列。
- `moo:auth` / `moo:api` 只识别 controller 中真实定义过的方法。
- `moo:schema` **不支持多级目录**。
- `moo:controller` 依赖 `:insert_code_here:do_not_delete` 标记,标记被删 = 路由插不进去。
