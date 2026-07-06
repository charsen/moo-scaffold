# moo-scaffold 项目总览

> 文档用途：面向准备接入或评估 moo-scaffold 的 Laravel 开发者，说明本工具**是什么、解决什么问题、由哪些模块构成、提供哪些功能点**。
>
> 本篇只讲「是什么 / 为什么」；**怎么装、怎么跑起来**请看操作手册 [`docs/guide/`](guide/README.md)（入口：[01-install 安装](guide/01-install.md) → [03 命令速查](guide/03-cli-reference.md) → 各模块手册）。

---

## 一、一句话介绍

**moo-scaffold 是一个 Laravel 后端「Schema 驱动」研发提效套件：写一份 YAML，一键产出 Model / Resource / Controller / Request / Migration / 接口文档 / ACL / 多语言全套代码，并附带一个挂在 `/scaffold/*` 的内置研发后台（可视化数据库设计器、类 Postman 接口调试器、ACL 权限视图、配置中心、开发文档中心、运行时错误监控）。**

---

## 二、立项背景与定位

### 2.1 解决的问题

Laravel 后端日常开发中，「建表 → 写 Model → 写 Resource → 写 Controller → 写 Request 校验 → 写 Migration → 维护接口文档 → 配权限 → 配多语言」是一条高度重复、易错、且彼此口径必须一致的链路。手工维护时：

- **重复劳动**：一张表 8~15 个字段，要在 6~8 个文件里重复声明字段、类型、校验、注释。
- **口径漂移**：Migration 写了 `varchar(128)`，Request 校验写了 `max:64`，接口文档又写了别的——三处不一致是常态。
- **文档滞后**：接口文档靠人维护，永远落后于代码。
- **联调成本**：改完接口要切到 Postman 重新配参数、配 token、配环境。

moo-scaffold 把这条链路收敛为**单一事实源（一份 YAML）+ 一条生成流水线 + 一个可视化后台**，让「改 schema」成为唯一入口，其余产物自动派生、自动保持一致。

### 2.2 目标用户与定位

- **个人 / 小团队 PHP 项目的开发提效工具**——业务面广，功能克制，装上就用。
- 通过 `composer require --dev charsen/moo-scaffold:^2.1` 装到任意 Laravel 12 / PHP 8.2+ 项目。装好后须先用 CLI `moo:account:add` 建首个账号，才能登录 `/scaffold/*` 后台。
- 它是一个有主见的参考实现，不是无约束的通用脚手架；最佳效果来自约定一致的 Laravel 项目。

### 2.3 设计哲学与边界（贯穿一切设计决策）

| 原则 | 含义 |
|---|---|
| **业务面广，功能必须简单** | 涉及 DB 文档、API 文档+调试、ACL、运行时错误、账号、配置 UI、Schema 设计器，但每一块都不堆复杂特性、不做多层兜底。「备份历史 / 修改前快照 / 多步撤销 / 操作审计」默认劝退——能走 git 就走 git。 |
| **dev-only 写、prod 只读** | 所有「写」类功能（配置编辑、账号增删、代码生成、Schema 迁移）只在开发环境启用；生产环境锁**高风险写簇**（Schema 设计器 / 账号 / 配置 / 手动推云），接口调试器等低风险写仍可用（详见「六、安全模型」）。两条强制防线：CLI `config('scaffold.only_in_local')` + Web `EnforceScaffoldWritable` 中间件。因为有这条边界，工具**不需要**复杂权限模型 / 操作审计 / 撤销链。 |
| **UI/UE 追求完美，但完美 ≠ 功能复杂** | 视觉、交互、暗黑模式、CSP 兼容、动效值得反复打磨，但视觉投入不能借口要求新增功能复杂度。 |
| **codegen 模板就是编码规范** | `stubs/*.stub` 与 `src/Foundation/` 基类是「PHP 项目应该长这样」的规范本身，改这里按「改规范」的严肃程度对待。 |

---

## 三、技术栈与接入方式

| 项 | 说明 |
|---|---|
| 运行环境 | Laravel 12 · PHP 8.2+ |
| 形态 | 独立 Composer 包（单一 Service Provider 注册命令、路由、视图、单例） |
| 前端 | Blade 视图 + Alpine.js（CSP-safe build）+ jQuery（接口调试器）+ SCSS 编译 CSS；视图通过 `loadViewsFrom` 直接服务，静态资源经 `vendor:publish` 同步到宿主 `public/vendor/scaffold/` |
| 数据存储 | **自身无数据库**——scaffold 不建任何表，数据库设计器 / `moo:migration` / `moo:db:audit` 操作的都是**宿主项目的 DB**。自身的运行期数据（账号 / 运行时错误、慢 SQL 临时缓冲）全是磁盘 YAML；schema 缓存落 `storage/scaffold/`；运行时错误 / 慢 SQL 真源在云端 |
| 接入方式 | 常规项目通过 Packagist 安装；开发本包时可用 composer `path` 仓库（symlink，改源码实时生效） |
| 配套生态 | moo-scaffold-cloud（运行时错误 / 慢 SQL / Todo 的云端真源与查看）+ [moo-chrome-dev-tool](https://github.com/charsen/moo-chrome-dev-tool)（bug/待办直发云端） |

---

## 四、系统架构总览

moo-scaffold 在同一个 Service Provider 下做两件相对独立的事，构成两大支柱：

```
┌─────────────────────────────────────────────────────────────────┐
│  支柱一：Schema 驱动的代码生成器（CLI，php artisan moo:*）         │
│                                                                   │
│  scaffold/database/*.yaml  ──moo:fresh──▶  storage/scaffold/ 缓存  │
│        (单一事实源)                            (models/tables/...)  │
│                                                    │              │
│                                                    ▼              │
│   moo:free 一键流水线：Model ▸ Resource ▸ Controller ▸ i18n        │
│                       ▸ Auth(ACL) ▸ Migration ▸ (可选)API ▸ View   │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  支柱二：挂在 /scaffold/* 的内置研发后台（Web）                     │
│                                                                   │
│  数据库设计器 · API文档+调试器 · ACL视图 · 配置中心 · 账号管理       │
│  · 字典浏览 · 开发文档中心 · 运行时错误/慢SQL（重定向云端）          │
│  · 云端汇聚控制台                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**核心契约**：用户只改 YAML；`moo:fresh` 把 YAML 解析成缓存；**所有其它生成器读缓存，不读 YAML**。手改 YAML 后不跑 `moo:fresh` = 生成器看到旧数据（数据库设计器保存时会自动同步跑一次 `moo:fresh` 刷缓存，best-effort，失败仅警告）。

生成产物分两类：
- **每次重写**（不要写业务逻辑）：`*Trait.php`、Enum 文件。
- **只生成一次，可安全手改**（除非 `--force`）：`Model.php` / `ModelFilter.php` / `ModelFactory.php` / `Model.ts`。

---

## 五、分模块功能清单

### 模块一：Schema 与代码生成器（CLI 命令）

YAML 驱动的一条生成流水线。**生成类命令 dev-only**（非 local 环境直接退出，防线即 `config('scaffold.only_in_local')`）；以下运维 / 协同类命令**例外，任何环境可跑**（scaffold 自有的显式 `requiresLocalEnvironment = false`）：`moo:fresh`（缓存被 git 忽略，生产需重建）、`moo:account:add`（首账号引导）、`moo:db:audit`（只读对账，含 prod 核对）、`moo:scaffold:merge-yaml`（多端同步脚本调用）；另有 `moo:cloud:push` / `moo:cloud:mcp` / `moo:monitor:migrate`（生产机推云 / AI 接入 / 旧版迁移）由依赖包 [moo-monitor-laravel](https://github.com/charsen/moo-monitor-laravel) 提供，不受 `only_in_local` 限制。

| 命令 | 功能点 | 说明 |
|---|---|---|
| `moo:init "作者名"` | 初始化 | 建 scaffold 目录骨架，把作者名写入 `.env` 的 `SCAFFOLD_AUTHOR`（生成器在文件头注释署名） |
| `moo:schema {Name}` | 新建模块 | 创建一个新的模块 schema 文件 `scaffold/database/{Name}.yaml` |
| `moo:fresh` | 解析缓存 | 把 YAML 解析成 `storage/scaffold/` 下缓存（models.php / tables.php / fields.php / enums.php / controllers.php / model_ids.php），增量维护 `_fields.yaml`。**所有其它生成器的数据源** |
| `moo:free {app} {schema} -a` | 一键流水线 | 顺序跑完：Fresh → Model → Resource → Controller → Multilingual → Auth → Migration →（可选）API。`{app}` 取自 `config/scaffold.php` 的 `controller` 段键名（默认提供 `admin` / `api` 两个 app，各自声明控制器路径与路由文件）；两个参数均为可选，省略时交互式选择 |
| `moo:model` | 生成 Model | Model / ModelFilter / ModelFactory / Trait / Enum，可选生成 TypeScript model（`Model.ts`）；`-F` 写 DatabaseSeeder |
| `moo:resource` | 生成 Resource | 生成 API Resource 文件（基于 `BaseResource` 等 Foundation 基类） |
| `moo:controller` | 生成 Controller | Controller / Request / Trait 文件，并把新路由插入宿主 `routes/admin.php`、`routes/api.php` 的 `// :insert_code_here:do_not_delete` 标记处 |
| `moo:migration` | 生成迁移 | 从 schema 生成 database migration（与设计器走**同一套** diff + writer，保证 CLI 与 GUI 口径一致） |
| `moo:i18n` | 多语言同步 | 同步 i18n 语言文件：model 枚举、validation attributes、db 字段中文名 |
| `moo:auth` | ACL 生成 | 从**真实路由**重建 ACL 配置、语言文件与可视化数据（按 app/模块/控制器/动作分层） |
| `moo:api` | 接口文档 | 从路由生成 API YAML 文档，记录 publish 历史 |
| `moo:view` | 前端脚手架 | 生成前端 Vue 页面脚手架（index / trashed / show） |
| `moo:adder` | 增量追加 | 给**已有** controller 增量追加单个 action + 路由（不必跑整条流水线） |
| `moo:db:audit` | DB 对账 | 只读对账 YAML 与实际数据库（列类型 / varchar size / 单列 unique 索引），报告漂移 |
| `moo:snapshot:init` | 基线快照 | 给现有 schema 一次性落初始 baseline 快照（设计器做 diff 的基线，必跑一次） |
| `moo:scaffold:merge-yaml` | 冲突合并 | 冲突 yaml 文件 last-write-wins 自动合并（多端同步脚本调用） |
| `moo:account:add` | 账号引导 | 新增开发人员账号（缺省字段交互式 prompt；首个账号引导用，其余走 Web UI） |
| `moo:cloud:push` | 推送云端 | 把本地 runtime 错误 / 慢 SQL 增量、幂等推送到 moo-scaffold-cloud（moo-monitor-laravel 提供，命令名不变） |
| `moo:monitor:migrate` | 旧版迁移 | 把旧版本地 yaml / 游标平移到 `storage/moo-monitor/` + `.env` 改名体检（moo-monitor-laravel 提供，替代已退役的 `moo:cloud:adopt`） |
| `moo:cloud:mcp` | AI 接入 | 以 MCP server 形式把云端 runtime 错误 **+ 待办** 暴露给本仓 AI（六工具）：runtime 三件套 `list/get/resolve` + 待办三件套 `list/get/update_status`（认领 → 处理 → 闭环）（moo-monitor-laravel 提供） |

**关键生成器内部组件**（`src/Generator/` + `src/Adder/`）：FreshStorageGenerator（缓存）、CreateModel/Resource/Controller/Api/View/TSModel/SchemaGenerator、UpdateAuthorizationGenerator（ACL）、UpdateMultilingualGenerator（i18n）、ControllerAdder/RouterAdder（增量）。

---

### 模块二：数据库设计器（Web · `/scaffold/db/designer`）

可视化编辑 `scaffold/database/*.yaml`，免手写 YAML。dev-only 写，生产只读。

| 功能点 | 说明 |
|---|---|
| 可视化改 schema | 表、字段、索引、枚举、模型类、控制器配置全部 GUI 编辑，落盘即 YAML（YamlFormatter 保留注释 + canonical key 顺序，git diff 干净） |
| 字段行内编辑 | 类型 / 大小 / 必填 / 默认值 / 中文名 / unsigned / 精度等，按类型自动派生可编辑性 |
| 字段改名保数据 | key 列行内改名 → 自动记 rename hint，走 `renameColumn`（保数据），而非 drop+add |
| 索引编辑 | 单字段索引（字段表下拉）+ 多字段复合索引（独立卡片）；区分 **app-level unique**（落 Request 校验 `Rule::unique()->whereNull('deleted_at')`，软删行不占名额，migration **不**加 DB 约束）vs **DB-level unique**（migration `$table->unique()` 强约束 + Laravel 默认 unique 校验）。双语义详见 [`yaml-style.md`](yaml-style.md) §五 |
| 枚举编辑 | 枚举键 / 值 / 中英标签可视化编辑 |
| AI 翻译辅助 | 中文字段名 / 枚举标签 → snake_case 英文标识符 + 英文 Label；字段拼写检查（标记疑似 typo，不自动改）；表名简写生成。走 DeepSeek（见模块八） |
| AI 批量加字段 | 输入「小区名称, 物业类型, 楼栋数」，AI 翻译成 yaml 字段段批量插入 |
| 新建 / 删 / 改名 schema | 草稿态 schema 可改名、删除；锁定态（已生成 migration）拒绝 |
| 删表闭环 | 删 yaml 表节点后自动接力 diff + 生成 drop migration + 联动清 snapshot，UI 返回 migration 文件名，无需用户手动跑 |
| 迁移预览 / 生成 | 设计器内直接预览即将生成的 migration PHP，确认后生成；与 `moo:migration` 同一套 diff + writer |
| 迁移合并（compact） | 把同一张表的「1 个 create + N 个 update」迁移合并成单一 create（dry-run 预览 + execute 两段式），带三重兜底（rename/drop 中间态 / schema drift / git push 检测） |
| 删 migration 文件 | 仅当 migrations 表无该文件记录（即从未执行过）时允许删除，联动 snapshot 处理 |
| 反向依赖警告 | 删字段时扫整个 codebase 查谁引用了该字段（Controller/Model/Filter/Resource/Request/Trait/TS），分「自动清 vs 手动清」给出一键 vim 命令 |
| 基线快照机制 | `.snapshots/{Schema}.yaml` 记录上次成功 migrate 时的 yaml，作为 diff 基线；跨成员 / 分支随 git 同步；baseline 漂移检测防生产冲突 |

**设计器核心服务**（`src/Designer/`）：SchemaLoader（YAML round-trip 加载 / 写回）、SchemaDiffService（diff 引擎）、MigrationWriter（生成 migration SQL）、SnapshotStore（基线快照）、MigrationCompacter（迁移合并）、SchemaDbAuditor（DB 对账）、GitInspector（git 状态检测）、TranslationService（AI 翻译）、YamlFormatter（保留注释的 yaml 格式化）。

---

### 模块三：API 文档与调试器（Web · `/scaffold/api` + `/scaffold/api/request`）

基于 YAML（而非手维护）的接口文档 + 类 Postman 调试器。

| 功能点 | 说明 |
|---|---|
| 接口文档 | 按 app / 模块 / 控制器 / 动作分层展示，从生成的 API YAML 渲染 |
| 接口调试器 | 类 Postman：填参数、选环境、发请求、看响应。参数从 FormRequest 校验规则 + YAML 自动派生 |
| 多接口 tabs | 同时开多个接口 tab，参数编辑值零丢失，响应面板按 tab state 还原；软上限 10 个，右键菜单批量关闭 |
| 自动缓存参数 | 「上次填了啥」按接口 + host + 客户端维度缓存，切回自动恢复表单 |
| 自动 Token | 登录类接口自动从响应抓 token，按 host 维度持久化，其它请求自动带上 |
| 环境切换 | 多 host（开发 / 测试 / 正式）下拉切换，SSRF 白名单仅允许配置内的 host |
| 后端代理 | 浏览器请求经 scaffold 后端代理转发（`POST {前缀}/api/proxy`，默认即 `/scaffold/api/proxy`——挂在 scaffold 路由前缀组内，**不是**宿主 `routes/api.php` 的 `/api/*`；强制 TLS 校验、不 follow redirect、origin 白名单防 SSRF、throttle 防滥用） |
| 响应渲染 | JSON 折叠视图（XSS 转义）、响应头、耗时 / 大小 / 状态码、422 校验错误友好展示 |
| **表单预览** | 调创建 / 编辑接口拿到 widget 配置后，第三个 tab 渲染可操作表单，验证数据正常 + 控件能操作。按常见后台表单组件约定覆盖 input/select/cascader/radio/checkbox/date 系列/rate/upload/editor 等 widget 类型，输出实时 JSON |
| 最近调试记录 | localStorage 历史 + 分页 + 一键回填（参数 / headers / host），敏感字段（密码 / token / Authorization）脱敏存储 |
| JSON 编辑视图 | 参数表 ↔ JSON 双向切换，支持粘贴整段 JSON 一键回填（含嵌套 / 数组参数） |

**核心服务**：ApiSchemaService（接口 schema 解析）、ApiController（文档 + 调试 + 代理 + 缓存 + 记录）。

---

### 模块四：ACL 权限（Web · `/scaffold/routes`）

| 功能点 | 说明 |
|---|---|
| ACL 生成 | `moo:auth` 从**真实路由**反推，按 app/模块/控制器/动作分层生成 ACL 配置 + 语言文件 |
| 路由 / ACL 查看 | `/scaffold/routes` 单页展示全部接口路由 + ACL key（明文 + md5），支持搜索、只看白名单、按模块折叠 |
| 一键调试 / 文档 | 每条路由可直接跳转接口调试器 / 接口文档 |
| ACL key 加密 | 可配 md5 加密别名 key |

（旧 `/scaffold/acl` 路由已并入 `/scaffold/routes`，保留 302 跳转不坏旧书签。核心服务：AclActionResolver、AclDocumentLoader。）

---

### 模块五：配置中心（Web · `/scaffold/config`）

可视化编辑 `config/scaffold.php` 与 `.env`。dev-only 写，生产只读（红条 banner + 写按钮一刀切灰）。四组导航：

| 分组 | 功能点 |
|---|---|
| 基础配置 | 编码作者 / 雪花 ID / 配置 UI 开关 / 鉴权（登录有效期、cookie、ACL 校验）/ 路径配置 / 路由前缀 / 调试 Host 映射 / 代理超时。env 字段写 `.env`、file 字段写 `config/scaffold.php`（写完自动清 config cache） |
| AI 配置 | DeepSeek 翻译上游（base_url / api_key / model / timeout / connect_timeout / max_tokens / temperature），存 `scaffold/ai.yaml`（可随宿主项目同步；注意 api_key 会以明文进入 git history，公开业务仓不要提交该文件），运行时读取、改完即时生效 |
| Env 镜像 | 展示当前 `.env` 全量内容（敏感字段自动掩码），只读 |
| 人员管理 | 链到账号管理（见模块六） |

**核心服务**：ConfigManager（分组 / 字段读写分发）、ConfigSourceScanner（静态扫描 env 来源）、EnvFileEditor（行级原地改 .env）、PhpFileEditor（改 config php）、AiSettingStore（AI 配置存储）。

---

### 模块六：账号管理（Web · `/scaffold/accounts`）

| 功能点 | 说明 |
|---|---|
| 开发人员账号 | 管理 `/scaffold/*` 后台的登录账号；YAML 存储（`scaffold/accounts.yaml`）+ bcrypt 密码哈希 |
| CRUD + 启停 | 列表 / 新增 / 编辑 / 删除 / 启用停用；首个账号 CLI 引导（`moo:account:add`），其余走 UI |
| 角色 | admin（可改任何账号 / 配置）/ member（仅自管资料） |
| 安全守护 | 不能删 / 停用自己；不能删 / 降级最后一个启用 admin（store 层兜底） |

**核心服务**：AccountStore + ScaffoldAuthenticate 中间件（cookie 认证；加密 / 签名的实际实现在 `src/Auth/ScaffoldAuth.php`——AES-256-CBC 加密 + HMAC-SHA256 签名，中间件只是调用方）。

---

### 模块七：运行时错误 / 慢 SQL 监控与云端汇聚（与 moo-scaffold-cloud 协同）

> 运行时错误、慢 SQL 本地自动捕获成临时缓冲，经 `moo:cloud:push` 汇聚到 moo-scaffold-cloud 集中查看 + 处置；Todos 由 Chrome 扩展直发云端。本地无查看器，访问自动重定向云端。
>
> **整条「采集 → 缓冲 → 推送 → MCP」链路由依赖包 [moo-monitor-laravel](https://github.com/charsen/moo-monitor-laravel) 提供**（composer 自动带入；不用 scaffold 的项目也可单独装它接云端）。scaffold 保留 UI 壳：`/scaffold/cloud` 控制台、首页面板、302 跳转。

| 功能点 | 说明 |
|---|---|
| 前置配置 | `config/moo-monitor.php` 的 `cloud` 段（moo-monitor-laravel）：`MOO_MONITOR_CLOUD_ENABLED=true` + `MOO_MONITOR_CLOUD_TOKEN`（在云端「接入 Token」页生成，需同时具备 runtimes + slow_queries 两个能力）；`MOO_MONITOR_CLOUD_URL` 默认 `https://c.mooeen.com`，一般无需改 |
| 运行时错误捕获 | MonitorProvider 自动挂 reportable 钩子（宿主零接入，WeakMap 防双计），异常自动落盘（trace + 源码片段 + 自动脱敏敏感字段），按 hash 聚合、日上限防刷 |
| 慢 SQL 监听 | 监听 `QueryExecuted`，超阈值 SQL 自动捕获，按 normalized SQL + file:line 聚合累加次数 |
| 临时缓冲 + 上云 | 本地仅作临时缓冲（`storage/moo-monitor/`，自带 .gitignore），`moo:cloud:push`（任何环境可跑，生产机即靠它推云）增量、幂等推送到云端后回收（CloudSync 用 flock 串行化游标防并发丢失）；`/scaffold/runtimes`、`/scaffold/sql-slows` 访问自动重定向云端 |
| 生产常驻推送 | `cloud.enabled` + `cloud.schedule` 同真时，MonitorProvider 自动挂**每分钟** `moo:cloud:push` 调度（带 10 分钟防重叠锁）——生产只需宿主跑 `schedule:run`，无需自建 cron |
| 心跳哨兵 | `moo:cloud:push` 每次运行（**含无数据的空跑**）都向云端 `/api/v1/heartbeat` 打点，云端据此发「推送中断」（push_stale）告警——所以即使暂时没数据，推送调度也必须保留 |
| 旧版迁移 | `moo:monitor:migrate` 把旧版本地数据 / 游标平移到 `storage/moo-monitor/` + `.env` 改名体检（替代已退役的 `moo:cloud:adopt`） |
| 首页云端面板 | `/scaffold` 首页用提报 token 回拉本项目云端只读汇总（三类统计 + 最近 runtimes / todos），缓存 60s，云端故障不拖垮首页 |
| 云端控制台 | `/scaffold/cloud` 本地缓冲状态总览 + 云端入口 + 手动推送 |
| Todo 收件箱 | Chrome 扩展（moo-chrome-dev-tool）把 bug / 待办**直发云端**，scaffold 不存 todo（无路由 / 本地存储）；AI 处理走 `moo:cloud:mcp`（见模块八） |

**核心服务**（除前两个外均在 moo-monitor-laravel）：CloudController、CloudRedirectController（scaffold UI 壳）；RuntimeErrorRecorder、SqlSlowListener / SqlSlowRecorder、ExceptionDispatcher、CloudClient、CloudSync（`Mooeen\Monitor\*`）。

---

### 模块八：AI 辅助（DeepSeek / OpenAI 兼容上游）

| 功能点 | 说明 |
|---|---|
| 字段名翻译 | 中文字段描述 → 最简洁 snake_case 标识符 + 类型推断 + size 建议（带本仓命名风格样本，模仿既有约定） |
| 枚举键翻译 | 中文枚举标签 → snake_case 键名 + 英文 Label（强制优先单词，对齐本仓 enum 风格） |
| 字段拼写检查 | 标记疑似 typo（不自动纠正，拼音 / 人名 / 品牌名 / 缩写一律放行） |
| 表名简写 | 模块短名 + 中文表名 → 符合约定的 snake_case 复数表名 |
| 配置 | 走 `/scaffold/config` → AI 配置 GUI 编辑，存 `scaffold/ai.yaml`；temperature / max_tokens / connect_timeout 等调参可配 |
| 未配置时行为 | AI 上游未配置时各 AI 入口不崩溃：后端抛 `AiNotConfiguredException`，接口返回 503 `AI_NOT_CONFIGURED`（上游错误 / 超时分别返回 502 / 504），其余功能不受影响——先到 `/scaffold/config` → AI 配置填好上游即可 |
| 云端 runtime / 待办 AI 处理 | `moo:cloud:mcp` 以 MCP server 暴露云端 **runtime 错误 + 测试/产品提报待办** 给本仓 AI（六工具，凭据复用 `moo-monitor.cloud` 的提报 token）：runtime 三件套 `list_open_runtimes` / `get_runtime` / `resolve_runtime`（先读源码后改、验证通过才闭环）；待办三件套 `list_open_todos` / `get_todo` / `update_todo_status`（`in_progress` 认领 → `done` 闭环） |

**核心服务**：TranslationService（含 retry / 脱敏 / JSON 解析兜底 / 输出校验）。

---

### 模块九：字典浏览（Web · `/scaffold/dictionaries`）

| 功能点 | 说明 |
|---|---|
| 枚举字典 | 浏览 schema 中定义的所有枚举（字段 → 键 → 值 → 中英标签），按模块组织，便于查阅业务字典 |

### 模块十：开发文档中心（Web · `/scaffold/docs`）

| 功能点 | 说明 |
|---|---|
| Markdown 文档 | 在后台写设计 / 流程 / 功能文档，纯 `.md` 文件存 `scaffold/docs/`、入 git，历史走 git（无快照 / 撤销 / 审计）。左写右实时预览 |
| 深链 shortcode | 正文直接嵌入**接口调试 / 接口文档 / 数据库文档**的活链接（引用 picker 一键插入），点击新窗打开对应页 |
| Mermaid 流程图 | 支持 Mermaid 流程图（隔离 iframe 渲染，单独放宽 CSP） |
| 环境约束 | 团队本地编辑、生产只读预览（同 dev-only 写防线）|

**核心服务**：DocsController、DocsRepository（存储 + 导航树）、`src/Support/Markdown/`（渲染）。

### 模块十一：扩展包一等公民 —— 出身模型（横切设计器 / codegen / 文档中心）

| 功能点 | 说明 |
|---|---|
| 自动纳管软链扩展包 | 软链安装(composer path repo)且带 `scaffold/database/` 的扩展包(如 moo-system / moo-radar)被 `PackageRegistry` 自动发现；设计器 / 数据库文档 / 字典 / 文档中心的列表按**出身分块**呈现(📦 标识) |
| 生成落包仓 | 对包 schema 跑 `moo:free admin <X>`，Model / Controller / Request / Resource / Migration / 路由 / 词条全部落**包自己的目录**，host 零改动；ACL / api 文档 / host i18n 等聚合物仍落 host |
| 写权硬线 | 软链包(realpath 逃出 vendor)= 可写；vcs 拷贝包 = 只读(设计器 / codegen / docs 全链硬拒)。详见 [guide/18-package-schema](guide/18-package-schema.md) |

---

## 六、安全模型

| 防线 | 机制 |
|---|---|
| dev-only 写 | CLI：`config('scaffold.only_in_local')` 默认 true，非 local 环境生成器直接退出（7 个运维 / 协同类命令例外，见模块一）。Web：`EnforceScaffoldWritable` 中间件在 `APP_ENV=production` 或 `SCAFFOLD_CONFIG_READONLY=true` 时**只锁四个高风险簇**的写请求——`db/designer*`、`accounts*`、`config*`、`cloud/push`；其余写端点（接口调试的记录 / 缓存 / 代理、CSP 上报）即使生产也放行，读类页面照常服务 |
| 登录鉴权 | cookie session（AES-256-CBC 加密 + HMAC-SHA256 签名，实现在 `src/Auth/ScaffoldAuth.php`），账号源 `scaffold/accounts.yaml`，登录限流 5 次/分钟/IP |
| CSRF | 受保护路由走 `VerifyCsrfToken`；webhook 端点（CSP 上报）刻意隔离在子组外 |
| CSP | Alpine 走 CSP-safe build，模板禁内联表达式；CSP 违规上报 `/scaffold/csp-report` 并写 log |
| SSRF | 接口调试代理仅允许配置内 host（origin 白名单 + 协议白名单），throttle 防滥用 |
| 路径穿越 | 运行时数据路由严校验 + store 层 `preg_match` 双层防 path traversal |
| 敏感信息 | 配置 / env 镜像敏感字段掩码；调试历史密码 / token 脱敏存储；运行时错误自动脱敏 |
| Web 层 codegen 隔离 | 全部 `moo:*` 命令 console-only，任何 Web 入口禁止 `Artisan::call('moo:*')` |

---

## 七、质量保障

| 项 | 说明 |
|---|---|
| Pest Feature 测试 | Orchestra Testbench + Pest 3，覆盖生成器 / SchemaLoader / Diff / 配置 / 账号 / 接口调试 / 安全等，530+ 测试用例 |
| Playwright e2e | 独立 npm，覆盖设计器 / 接口调试器关键 user flow |
| 静态门禁 | `tools/ui-checks/`：HTTP smoke + 静态规则（业务视图无内联 style / 无硬编码 hex）+ asset 存在 + CSS gzip 预算 |
| 代码风格 | Laravel Pint（统一格式化） |
| 回归锁 | 每个修复都补「经 revert 验证」的回归测试，沉淀进套件 |

---

## 八、配套生态

| 组件 | 角色 |
|---|---|
| **moo-scaffold**（本包） | 代码生成器 + 内置研发后台 |
| **moo-scaffold-cloud** | 运行时错误 / 慢 SQL / Todo 的云端真源与查看（多项目汇聚） |
| **Chrome 扩展**（moo-chrome-dev-tool） | bug / 待办直发云端 |
| **下游 Laravel 项目** | 通过 composer 接入；多端 / 多人通过 git 同步可入 git 的 scaffold 数据 |

---

## 九、目录结构速览

| 层 | 路径 | 角色 |
|---|---|---|
| Provider | `src/ScaffoldProvider.php` | 注册命令、路由、视图、单例 |
| CLI 命令 | `src/Command/` | 全部 `moo:*` artisan 入口 |
| 生成器 | `src/Generator/` + `src/Adder/` | 命令背后的代码生成实现 |
| 设计器 | `src/Designer/` | 可视化 schema 编辑 + diff + migration |
| 横切服务 | `src/Support/` | 账号 / 配置 / 云端 / 运行时 / ACL 等 |
| 登录认证 | `src/Auth/` | ScaffoldAuth——登录 cookie 加密（AES-256-CBC）+ 签名（HMAC-SHA256）的实际实现，ScaffoldAuthenticate 中间件的底层 |
| 其它横切 | `src/Concerns/` · `src/Rules/` · `src/Exceptions/` | 共享 trait / 表单校验规则 / 异常类 |
| Stubs | `stubs/` | 所有代码生成模板（= 编码规范） |
| Foundation | `src/Foundation/` | 生成代码依赖的基类（Controller / FormRequest / BaseResource / Actions） |
| Web 控制器 | `src/Http/Controllers/` | Account / Api / Auth / Cloud / CloudRedirect / Config / Designer / Docs / Route / Scaffold |
| Web 视图 | `src/Http/Views/` | Blade（`<x-scaffold::*>` 匿名组件库） |
| 中间件 | `src/Http/Middleware/` | ScaffoldAuthenticate / EnforceScaffoldWritable / SecurityHeaders |
| 默认配置 | `config/config.php` | publish 后成宿主 `config/scaffold.php` |

---

## 十、一句话总结（评审记忆点）

> **一份 YAML 是事实源，一条流水线产出全套后端代码，一个 `/scaffold` 后台覆盖「设计库表 → 调接口 → 看权限 → 改配置 → 抓错误」全研发链路；dev 可写、prod 只读，业务面广而功能克制。**
