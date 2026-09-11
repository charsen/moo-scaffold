# Changelog

## 2.1.22

- 配置相关写操作（保存配置、人员管理）收紧为仅 `admin` 可执行；`/scaffold/api/proxy` 的目标白名单在 `scaffold.hosts` 为空时不再回退请求的 `Host` 头，改用 `config('app.url')`，取不到则一律拒绝。
  > **升级注意**：若此前依赖「`hosts` 留空即等于当前站点」的隐式行为，请显式配置 `scaffold.hosts` 或确保 `APP_URL` 正确，否则代理请求会被拒绝。人员管理页的只读访问不受影响。
- 配置页敏感字段不再回显明文：表单渲染空白输入并提示「已配置，留空保持原值」，差异提示与「默认值」列同样掩码；敏感字段留空提交按「未修改」处理（`bool` 类型退化为只读）。需要清空请直接修改配置源文件或 `.env`。
- `moo:adder` 对交互输入的 `action`、控制器、`Request`、`Resource` 名做标识符与路径校验，非法即中止写入；修复锚点缺失、路由文件不存在等情况下的异常与「假成功」输出。
- 统一写盘的原子实现并保留原文件权限位：此前 `PhpFileEditor` / `EnvFileEditor` 的临时文件未继承原文件 `mode`，每次保存会把 `.env` 等更严格的权限位放宽到 `0644`；`SchemaLoader`、`SnapshotStore` 的写入一并接入（内容不变，仅原子性与权限）。
- 字段名与字段类型的判断改为单一来源（隐藏字段规则、Studly 转换、类型分组），并修掉字段类型 `mediumtext` / `longtext` 未生成 LIKE 搜索 scope 的遗漏。
- 生成 migration 后若 baseline 未推进（源 yaml 解析失败或快照损坏），命令行与设计器都会明确提示原因与后续动作，不再按「成功」静默返回，避免重复点击产出重复 migration。
- `scaffold/accounts.yaml` 解析失败时降级为空集并记录告警，不再因文件损坏而无法进入人员管理页进行修复。
- 内部测试脚手架与协作文档整理（e2e 登录态录制、宿主清理、文档口径），不影响运行时接口与数据库结构。

## 2.1.21

- 统一 `docs/` Markdown 的 `title`、`group` 与整数 `order`，规范文档导航名称、分组和排序，保留原正文及标签。
- 本次仅更新文档，无运行时接口或数据库结构变更。

## 2.1.20

- 研发计划与发版日志支持在 local 且未强制只读时编辑既有 Markdown，提供实时预览、显式保存、快捷键和未保存提醒；写入同时校验登录、CSRF、文件路径和版本冲突。
- 支持 frontmatter 的 title、group、order、tags，保留原始注释、空白、BOM 与换行；兼容空头部，错误 YAML 显示位置并拒绝保存，无效 order 不再被当作有效排序值。
- 软链与不可写文件隐藏编辑入口；断网和 15 秒超时保留输入，已落盘但响应丢失时，同内容重试可确认成功且不重复替换文件。
- 升级后重新发布 Scaffold 静态资源，以加载新增的本地 Markdown 编辑脚本。

## 2.1.19

- 修复发版日志与研发计划的默认目录：配置层分别使用 `../release-records` 和 `../plans`，Laravel 工程位于 `engine/` 时默认读取仓库根目录，无需设置路径环境变量。
- 两条读取链路仅使用配置值，不再内置 engine 目录默认值或自动回退；即使 engine 下存在旧副本，也只读取配置指向的目录。自定义环境变量仍可覆盖默认值。
- 补充真实配置加载、根目录与 engine 副本并存、根目录记录缺失及显式路径不回退的回归验证。升级后须刷新 Host 配置缓存，使新默认值生效。

## 2.1.18

- Scaffold 主菜单新增「发版日志」，只读浏览 Host 的 Markdown 发版记录，支持日期倒序、同日多份记录和标题过滤。
- 新增「研发计划」，默认打开 plans/README.md，支持目录分组、文件名自然排序、目录内文档链接及稳定章节锚点。
- 两类目录均可配置相对 Laravel 根目录或绝对路径，沿用 Scaffold 登录保护，拒绝读取目录外软链；不提供编辑、删除或重排操作。

## 2.1.17

- ACL 生成器按目标动作解析授权显示名，避免辅助上传动作覆盖被引用的办理、审批等业务动作文案；授权键与路由权限语义不变。
- 补齐目标动作、跨控制器引用和既有文案回归；包测试 706 项通过，3 项既有环境跳过。

## 2.1.16

- `moo:api` 与 `moo:auth` 在内容无变化时不再重写产物文件。此前 `CreateApiGenerator` 用整文件字节比对判定「无变化」，生成器任何一次排版调整（例如空 `code` 去掉尾空格）都会让全部历史 yaml 差一个字节而被整体重写、`@date` 集体刷新；`UpdateAuthorizationGenerator` 则无条件重写 `scaffold/acl/{app}.yaml`、`config/actions.php` 和 `lang/{lang}/actions.php`。现在全部改为语义比对：API schema 比较 `Yaml::parse()` 后的结构（注释与排版差异不算变化），ACL 文档比较剔除 `generated_at` / `generated_by` 后的内容，两类 PHP 产物比较 `return` 的数组本身，等价则跳过写入并报 `No changes`。解析失败（含重复 action key）仍按「有变化」重写，损坏文件不会被静默跳过。
  > 既有 yaml 的历史排版会原样保留，直到该 controller 真有接口变动时顺带规范化；需要立即全量归一用 `moo:api {app} -a -f`。
- `config/actions.php` 与 `lang/{lang}/actions.php` 新增生成戳注释头，记录用途、`@generated_by`、`@generated_at` 与「请勿手改」说明。头部不参与内容比对，所以内容没变时时间戳也不会被刷新。开头按 `<?php declare(strict_types=1);` 同行形态输出，比旧格式少触发 `declare_strict_types` 与 `blank_line_before_statement` 两条 Pint 规则。
  > **升级后第一次跑 `moo:auth` 会把这两类文件各重写一次**（补头部，`return` 的数组内容逐字节不变），之后保持稳定。

## 2.1.15

- 内置研发后台 action 全面使用 Scaffold FormRequest，所有用户输入经 `validated()` 消费，并新增路由反射与源码门禁防止退回通用 Request 或原始输入读取。
- 统一根目录协作文档命名为 `NOTES.md` 与 `TODOS.md`。

## 2.1.14

- Composer `dev` 分支新增 `dev-dev → 2.x-dev` branch alias，Host 测试 profile 可直接依赖 `dev-dev`，无需 root inline alias。

## 2.1.13

- Cloud 控制台在 `APP_ENV=local` 新增「清理开发噪音」：Cloud 仅把当前项目 local 环境中未解决的 Runtime / 慢 SQL 移入「已删除」，已解决记录与其它环境保持不动；本地只丢弃 cursor / partial ack 判定的待推记录，保留已同步 open 聚合锚点，同 hash 后续真实复发仍可重新打开。危险操作保留一次确认，不再要求输入确认文字。
- 最低 `moo-monitor-laravel` 版本提升到 `^0.1.14`，锁定 Cloud 联动清理、分类型同步锁、本地 pending 精确丢弃与 recorder cache 失效契约。
- 数据库设计器的扩展包列表改为响应式多列网格，充分利用横向空间；数据库文档侧栏切换菜单时保持原滚动位置，并消除恢复过程的顶部闪动。
- 顶栏与登录页统一使用主题感知品牌组件：亮色加载 `logo-light.png`，暗色加载 `logo-moon.png`，切换主题时尺寸不跳动；同步保留 SVG / Illustrator 设计源文件。
- 仓库发布改为 Gitee `origin` 与 GitHub `github` 两个远端分别直推并逐项核对，不再依赖定时镜像工作流。

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
