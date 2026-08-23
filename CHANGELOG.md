# Changelog

## 2.1.12

- `Concerns\Optional` 新增 `optionsAllowShowPage()` 标准扩展点，模型覆写并返回 `true` 后，会在正常列表与回收站的默认操作中增加 `type: show-page`；它与 `optionsAllowShow()` 相互独立且默认关闭，现有模型的操作集合保持不变。前端消费者需将 `show-page` 强制路由到独立详情页，`show` 原有的通用详情弹窗与 `showRoute` 兼容行为不变。

## 2.1.11

- `Concerns\Optional` 新增 `optionsAllowShow()` 标准扩展点，模型覆写并返回 `true` 后，会在正常列表与回收站的默认操作首位增加 `type: show`；默认值为 `false`，现有模型的操作集合保持不变。前端仍通过 Scaffold 的 `showRoute` 决定该动作打开通用详情弹窗还是跳转独立详情路由。

## 2.1.10

- `Foundation\FormRequest` 隔离数组子项与聚合控件规则：同时声明 `field` 数组规则与 `field.*` 子项规则时，`getFrontendRules()` 与 `formatFormConfig()` 都会把 wildcard 键折成父键，导致后写的子项规则覆盖父控件——表现为 nullable 字段被标必填、上传控件类型或显式默认值丢失、`string` 等元素规则被错误套到整个数组。现在父规则存在时不再为 wildcard 生成同名控件或合并其前端规则，wildcard 只保留 `multiple` 多选信号；父规则不存在的历史用法继续兼容。
  > ⚠ **对 Host 是可见变化**：同时声明父子规则的 Request，其 `form_widgets` 输出会由「重复/被覆盖的控件」变为单个正确的聚合控件。升级后若有 form 相关 baseline，需重跑并逐项归因——变化方向应是修复。
- `Generator\FreshStorageGenerator` 补齐 scaffold 公共非数据库字段：`page`、`page_limit`、`options`、`ids`、`please_enter`、`please_select` 六个运行时字段不出现在数据库 schema 中，此前需各项目手工维护翻译。现在 `moo:fresh` 会把缺项自动补进 `_fields.yaml` 的 `append_fields`，供 `moo:i18n` 同时生成 `validation.attributes` 与 db 翻译；**项目已手工维护的同名翻译优先，不会被覆盖**，字段顺序也保留。

## 2.1.9

- `Concerns\UsingSnowFlakePrimaryKey` 的主键生成改走框架标准的 `newUniqueId()` 扩展点（与 `HasUuids` 同型），`creating` 钩子委托给它。需要预分配主键的场景（如 host 的 `withUploadedImages`）可直接从模型取值，不必再依赖 `scaffold.snowflake` 容器绑定。

## 2.1.8

- 应用端统一由 `config/scaffold.php` 的 `controller` 注册表驱动：默认端调整为 `admin`、`mobi`、`web`，Host 可继续注册 RPA、Screen 等自定义端；生成器按端配置解析 Controller、Request、Resource、Test、路由模式和显示名称，不再把 `api` 同时当作移动端目录名。
- `moo:free`、`moo:controller`、`moo:resource`、`moo:test`、`moo:auth` 与 `moo:api` 对齐同一应用端契约；`route_mode = manual` 的业务端只扫描既有路由，不自动写入 `Route::iResource`，未注册的 Host 应用端会明确失败。
- `moo:api --sync-names` 可从 Controller DocBlock 同步模块、控制器、动作名称与动作说明；空名称会按缺失回填，无方法注释时不会用 `index` / `show` 等方法名覆盖已有文案。
- `Foundation\FormWidgetCollection` 的 editor 控件支持由业务包覆盖 `imageUploadUrl`，Host 可把富文本上传交给独立扩展包，而无需修改 Scaffold。

## 2.1.7

- `Foundation\FormWidgetCollection` 的 `putMore()` / `forget()` 改为自包含实现（`data_set` / `data_forget`），**不再依赖 host 注册的 `Collection::putMore` / `forgetMore` 宏**。此前 scaffold 的类反过来要求每个 host 在自己的 `AppServiceProvider` 里手抄一份宏（scaffold 自己不注册），漏抄即 `BadMethodCallException`，且脱离 host 的包测试环境里整条 `form_widgets` 链路根本跑不起来。`default` / `disabled` / `hidden` / `options` / `type` / `tip` / `isArray` / `setFilter` 全家与 host 侧 `->putMore(...)` 链式调用行为不变；host 那份宏保留不动，仍服务 host 自己直接链在原生 `Collection` 上的调用。
- 顺带取消 `putMore()` / `forget()` 原宏的「点路径最深 3 段」限制——控件属性本就可能嵌更深（如 `field.control.params.scope`），层级不再受限。

## 2.1.6

- 新增 `Support\OperatorContext` 队列显式操作人上下文（`runAs()` / `current()` / `clear()`）：`Concerns\HasOperator` 优先消费上下文中显式设定的操作人，未设置时回落原 `OperatorResolver` 解析——无 context 时行为逐字节不变，向后兼容。
- 新增 `Support\OperatorId::normalize()` 共享哨兵归一：无效操作人哨兵（`null` / 空串 / `0` / `'0'` / 全零串）统一归一为 `null`，其余原样透传；收敛 trail / attachment / radar 三包各自一份的哨兵判断口径，各包在其上保留自有额外处置（trail 的 `positiveId` 正数收紧、attachment / radar 的 `AuthenticationException`）。

## 2.1.5

- Cloud 手动推送按类型独立执行；某一类出现 partial ack 或待重试记录时，仍继续尝试另一类，最后统一汇总已确认、已隔离和失败事实。
- 最低 `moo-monitor-laravel` 版本提升到 `^0.1.13`，锁定同一 Host 多 `.env.XXX` 项目的 YAML、cursor、partial ack、同步锁、回收范围与自动 scheduler 环境隔离。

## 2.1.4

- Cloud 手动推送页面适配 Monitor 逐条确认契约：即使批次仍有待重试记录，也会如实累计并展示已确认、已隔离和本地回收数量，不再把 partial success 误报成整批失败。
- `CloudSync::sync()` 的真实 skipped reason 会透传到页面；分类型关闭、同类型同步锁竞争等原因不再统一误报为配置关闭或“已确认 0 条”。
- 只要本轮产生逐条确认或隔离结果，就立即失效首页 Cloud summary 缓存，避免页面继续展示旧状态。
- 最低 `moo-monitor-laravel` 版本提升到 `^0.1.12`，锁定 partial ack、同步锁、open 累计锚点和 MCP 分页契约。
- 与正式发布的 Monitor `v0.1.12` 组合回归通过：631 passed / 2042 assertions，3 skipped。

## 2.1.3

- B-01 方案 B：新增 `Contracts\OperatorResolver` + 默认 `Support\GuardOperatorResolver`（auth()->id()，未登录 null），开出 host 操作人身份注入缝。
- `HasOperator` 上移为共享 `Mooeen\Scaffold\Concerns\HasOperator`；生成器不再复制本地 Trait/stub，无身份统一写 null。
- Scaffold Provider 使用 `bindIf()` 注册默认实现，尊重 host 的统一身份绑定。

## 2.1.2

- Docs center: the bare `/docs` URL now opens a catalog home page with drag-and-drop ordering — rows within a group, or whole groups at once. Order is written back surgically to each doc's front-matter `order` line using gapped global numbering (10/20/30…), so diffs stay one-line clean.
- Docs center: full-text search across every doc source (host + packages) from the catalog page, with hit highlighting; result links carry `?hl=` so the reading page scrolls to and marks the first match.
- Docs center: prev/next navigation at the bottom of reading pages, following the same global reading order.
- Docs center UI polish: compact single-row header (title + search + count), dark-theme readability (zebra rows, accent group bars), roomier rows, grip-only drag handles, and the redundant slug column removed. New-doc template no longer hardcodes an `order`, so new docs sink to the end of their group until dragged into place.
- Scaffold admin sidebars are drag-resizable with persisted widths: all navigation trees share one width, the designer table list keeps its own.
- Shared runtime foundations moved into the package (translation merging loader, Eloquent base filter, snowflake primary-key concern); generators stop emitting per-app copies. Adds `tucker-eric/eloquentfilter` as a direct dependency.
- Generator/write hardening: new schema files are dumped through the YAML formatter, file-write failures throw instead of passing silently, and column `width`/`minWidth` values pass through as-is (no `px` suffix appended — the front end normalizes).
- Laravel 10, 11, and 12 are all supported (`laravel/framework ^10 || ^11 || ^12`), with a 2.x branch alias for path-repository development.
- UI copy punctuation normalized to full-width in Chinese contexts; CI adds a quality workflow (composer validate, Pint, tests, dependency audit).

## 2.1.1

- Extension-package controllers now generate and reference an in-package `HandlesResourceActions` trait, removing the package-to-host base-trait dependency.
- Request traits can delegate to package-owned tables (`scaffold.package_request_traits`).
- Internal consistency refactor with no behavior change: unified tri-state file-write reporting, added an `isForced()` command helper, extracted the API debugger's HTTP proxy into its own controller, and decoupled the utility layer from the schema loader.
- Expanded test coverage and hardened a configuration test against persistent test base paths.

## 2.1.0

- Initial public release based on the 2.x line.
- Includes the current schema-driven code generator, scaffold admin UI, database designer, API debugger, ACL tooling, configuration UI, docs center, and moo-monitor-laravel integration.
- Removes non-public workflow notes and handoff material from the public package.
