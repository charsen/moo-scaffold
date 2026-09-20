# Changelog

## 2.2.0

- **`/scaffold` 全部 API 端点统一为 `{message}` JSON 信封**（**行为变更**）。此前各控制器各自拼装响应、错误回执形态不一，消费者要逐处容错；现由 `Support\JsonEnvelope` 统一产出，机器码由异常类名派生（`BaseException` 迁移），三个 `Enforce*` 中间件与控制器基类同时收敛，并在框架层与前端层各加一道守卫。前端新增 `public/javascript/api.js` 统一解包层 `ScaffoldApi`，`designer.js` / `docs-editor.js` / `docs-home.js` / `local-markdown-editor.js` 全部改走它，旧的「两形态容忍」代码删除。**下游需注意**：① 直接消费 `/scaffold` JSON 的代码要按新信封解析；② 若曾 `vendor:publish` 前端资源，需重新发布以取得 `api.js` 与更新后的页面脚本。
- **`Utility` god-class 拆分**（重构，行为保真）：`src/Utility.php` 由 845 行降至 331 行，按职责外迁到 `Support\` 下的独立类，**不留转发** —— 3a 名归一 → `Support\ControllerName`（`addGitIgnore` 遗留项一并收口）；3b 文档元信息 → `Support\ActionMeta` + `Support\ActionDoc`（9 方法 / 33 调用点）；3b-2 路径 → `Support\Paths`（10 公开 + 1 private）；3b-3 登记表读取 → `Support\StorageRegistry`（9 方法 / 32 调用点）。两个高频名字归一方法保留一版 `@deprecated` 静态转发。**下游需注意**：直接调用 `Utility::getStoragePath()`（→ `Support\Paths`）/ `Utility::getModels()`（→ `Support\StorageRegistry`）等已外迁成员的地方需要改指向。
- **跨包姓名契约移出 scaffold**：改由私有 `moo-contract` 定义，scaffold 侧不再持有该契约。
- **`moo:account:add` 修复**：非交互模式下会静默创建空密码账号。
- 低风险优化收口 18 / 19 项：选择回落 `null` 的 4 处活口、删文件失败静默、路径 / 类型 / 只读 / 遍历 / 缓存 / 退出码 / 写失败守卫等。
- 回归：`MigrationWriter` 的 `emitUp` / `emitDown` 镜像新增三层守卫；`/scaffold` 信封的框架层与前端层两侧守卫。

## 2.1.25

- **新增通用字段契约 `Mooeen\Scaffold\Forms\FieldTypes`**：把「字段类型 → 规则 / 参数 / 归一化 / 展示」收成一份声明式登记，供所有表单生产者共享。`params` 是参数元 schema（`kind` / `default` / `min` / `max` / `max_length` / `max_items`，**不写 `default` 即必填**），`rules()` 只产出类型专属规则、`required` / `nullable` 由框架统一前置，并兼容可空文本参数；单选项集有上限，复杂主数据应由消费领域提供独立引用类型。新增运行时依赖 `brick/math` 做数值边界校验。
- **领域登记表可扩展前端控件**：`FieldTypes::supportedWidgets()` 默认返回 scaffold 的 `FormWidgetTypes::FORMER`，领域子类可覆写它登记已在前端实现的控件；`violations()` 改按 `static::supportedWidgets()` 校验，通用登记表不接入业务组件。回归：`FieldTypesTest`。
- **新增只读体检 `moo:audit:resource-keys`：找「Resource 原样透出的 json 列」里带整数键映射的地方。**
  背景：Laravel 的 `ConditionallyLoadsAttributes::removeMissingValues()` 会**递归**把「键全为数字」的嵌套数组
  `array_values()` 重排（本意是让删掉条件字段后带洞的**列表**仍序列化成 JSON 数组），但
  `{"1":"正常","2":"停用"}`、`{"4":12,"6":3}` 这类**整数键映射**会被误判成列表、键被抹掉：
  前端按值取标签错位，**读回来再保存就把键永久写坏**（`Rule::in(array_keys(options))` 还会从 `in:1,2` 变成 `in:0,1`）。
  命令按 `extra.moo-private-packages` 定位私包（读不到清单时退化扫 `vendor/*/*/src`），
  把「Resource 透出的列 ∩ json/array cast 列」逐个抽样（默认 200 行）递归判定，输出
  `包 / 资源 / 列 / 是否声明 preserveKeys / 抽样 / 危险行 / 键路径`；`--json` 给机器读、
  `--fail-on-danger` 命中即退出码 1（可当 CI 闸门）。纯只读，不碰数据、不改代码。
  实测本生态：28 处候选里只有 2 处真实命中（mini-app `field_params.options`、attachment
  `stat_file_type_meta`）。
  收口方式两条：① 手写 Resource 加 `public $preserveKeys = true;`（**必须是实例属性**，static 会落到
  `JsonResource::__get()` 报错；生成物上加会被 `-f` 覆盖）；② 出参别给整数键映射，转 `[{key|label}]`。
  模型不可加载 / 抽样失败（DB 不可达、表缺失）的列计为**未能核验**并显式告警：「没查到」不等于「干净」，
  此时 `--fail-on-danger` 的退出码只反映已核验的危险列；`--json` 顶层给出 `unverified` 计数。
- **`--allow=<包>:<资源>:<列>` 支持「复核后接受」的命中**（段可用 `*` 通配、可重复）：登记后不计危险、
  不影响退出码；陈旧条目（谁都没匹配上）与格式错误条目分别告警 / 单列，避免豁免悄悄失效。
- 回归：`AuditResourceKeysCommandTest` 6 项（判定器含带洞键 / 危险与安全列 / 已声明与 static 写法 /
  抽样失败的未核验口径 / `--allow` 与陈旧条目 / `--fail-on-danger` 退出码）+ `Support\NumericKeyMapDetector` 纯函数单测。
- **新增只读跨仓体检 `moo:audit:former-types`：断言「后端 `FormWidgetTypes::FORMER`」与
  「下游 admin SPA `former/config.ts` 的 `elComponents` 注册表」是同一个控件类型集合。**
  背景：后端 `FORMER` 是表单契约**可能下发**的 type 全集（也是 mini-app 等动态类型登记的白名单），
  前端 `elComponents` 是把 type 映射到渲染组件的唯一位置，两侧此前只靠 `FormWidgetTypes` 的注释
  「人工对齐」（plan 61 §2.1 / plan 64 §6.4 要求补自动化检查）：漏一边就是「后端下发新 type、
  前端静默走只读兜底」，或「前端注册了后端永不下发的死类型」。
  口径：后端取运行时类常量 `Mooeen\Scaffold\Support\FormWidgetTypes::FORMER`，前端取 `config.ts` 里
  `elComponents` 对象字面量的**顶层键**；只比**集合**不比顺序；两侧名称目前逐字同名，故无需别名映射
  （命令内保留 `ALIASES` 显式映射位，命名体系真分叉时登记，不做模糊归一）。`--spa=` 必填，可给
  SPA 仓根目录（按 `apps/admin/src/components/former/config.ts` 等约定位置探测）或直接给 `config.ts`。
  输出两边数量、只在前端有的、只在后端有的；`--json` 只出 JSON 适合脚本消费。
  **退出码：一致 `0` / 不一致 `1`（CI 闸门）/ 路径不存在、解析失败等读取错误 `2`** ——
  「读不到」绝不等于「一致」。纯只读：不写文件、不跑前端构建、不依赖 DB，任何环境可跑。
  实跑真实 SPA 仓库：两侧各 18 项，当前一致。
- 回归：`AuditFormerTypesCommandTest` 8 项（目录推导与文件直指 / 一致退出 0 / 前端多一项退出 1 /
  后端多一项退出 1 / 路径不存在与缺 `--spa` 报错退出 2 且不说「一致」/ 解析失败不得退化成空清单 /
  只比集合不比顺序）。

## 2.1.24

- **修复：给存量表补框架列（`deleted_at` / `created_at` / `updated_at`）不再被静默吞掉**。
  `SchemaDiffService::fieldDiff()` 原先对 `id / deleted_at / created_at / updated_at` 一律 `continue`（"system fields — skip"），
  于是「YAML 里给已有表加 `deleted_at` 开软删」这类变更**根本不会进 diff** —— `moo:migration` 直接回
  「无变更，跳过生成 migration」，使用者只能手写迁移文件（工具漏的活不该由人补）。
  现在：框架列的**新增**正常产出 `add`（`id` 除外 —— 主键只由 `create_table` 下发，永不后补）；
  框架列的**删除**仍然不自动落库（删 `deleted_at` 等于让历史记录静默"复活"、删时间列会丢审计线索），
  但会出一个 `FRAMEWORK_COLUMN_DROP` 高优告警，不再无声消失。
- **修复：`MigrationWriter` 现在能把新增的框架列写成框架方法**。`deleted_at: {  }` 在 YAML 里是**空定义**
  （真实类型由 `softDeletes()` / `timestamps()` 决定），此前若走 add 路径会落到 `resolveType('varchar')`
  生成一个 **varchar 垃圾列**。现在 add 路径特判：`deleted_at` → `$table->softDeletes()`、
  `created_at` / `updated_at` → `$table->timestamp('x')->nullable()`，与 `create_table` 的写法一致，
  并照常保留 `->after('...')` 以维持字段物理位置跟 YAML 一致；`framework_drop` 这类写入侧不认识的 op
  在 up/down 里被跳过（只走告警通道）。
- 回归：`SchemaDiffServiceTest` 两项（框架列新增能进 diff 且 `id` 不后补 / 框架列删除不产 drop 但出告警）
  与 `MigrationWriterTest` 两项（框架列渲染成框架方法且保 `after` 与 `down()` 反向删除 / `framework_drop` 被忽略）。

## 2.1.23

- **表单契约收口为单一来源**：控件类型词表与「规则 → 控件类型」的反推统一到 `Support\FormWidgetTypes`（`FORMER` 下发侧 18 项、`DEBUGGER_RENDERABLE` 预览侧 19 项，两者差异是既成事实而非待修漂移）；「规则 → 前端 `[{rule,msg}]`」从 `FormRequest` 私有方法提升到 `Support\FormFrontendRules`。调试器表单预览不再内联类型白名单，改由后端注入 `window.ScaffoldConfig.knownWidgetTypes`（同进程，不再靠人工跨仓同步——此前两份清单已互相漂移过一次）。
- **新增 `Foundation\RuntimeFormRequest`**：把「字段来自数据库」的运行时 schema 接进既有表单契约，只承载 `rules / options / layout`、不做类型编译，使 `FormWidgetCollection::makeForm()/makeSearch()`、`formLayout` 校验与调试器预览原样可用。它是同一契约的**第二个生产者**，不是第二套链路。
- **契约审计搬入本包**：新增 `moo:audit:form-contract`，宿主侧重复实现删除。判据收在 `Support\FormWidgetVisibility`，按可见性分桶（`hidden` / `disabled` / `layout` 外 / `waived`），**默认只报用户可见违规**；「刻意不实现」的字段用 Request 里的 `// @moo-waived <字段>: <原因>` 显式豁免（未标记的注释规则仍按可见违规报出，标记不是免罪符），`--include-hidden` / `--include-disabled` / `--respect-layout` / `--include-non-contract` / `--include-waived` / `--all` 逐级放宽口径。
- `FormRequest::formatFormConfig()` 给规则外的附加键打 `contract => false`，审计据此把「真正的契约外键」与「表单渲染需要的附加项」分开，后者默认不计违规。
- **生成器：非软删模型不再生成软删端点**。`trashed()` / `forceDestroy()` / `restore()` 整段外置为 `{{soft_delete_methods}}`，`show()` 不再 `withTrashed`（`{{show_find_or_fail}}`），列表 `deleted_at` 追加走 `{{trashed_list_append}}` 占位 —— 此前非软删表命中这些端点即抛 `BadMethodCallException` → 500。配套去掉**包侧**共享动作 trait 里的非软删运行态守卫：它要么不可达、要么恒为真，每包一份却一次也没拦住东西；host 侧 `BaseActionTrait` 的同款守卫**保留**，那里确有「模型非软删但端点仍在」的存量控制器。
- 新增 `Testing\ComposerProfiles`，把三份 manifest 的私包一致性断言收口到一处；新增只读命令 `moo:composer:docs`；移除 `moo:assets:check`（public 资源是否刷新交由研发判断，不为它扩大工具体积）。
- 补表单契约特征测试、调试器类型词表回归用例与生成器软删测试；e2e 前置与 `vendor:publish --tag=public` 的坑记入 `tests/Browser/README.md`。

  > **升级注意**：宿主若自行实现过契约审计命令（`audit:form-contract` 及配套可见性类），请删除并改用 `moo:audit:form-contract`，否则口径不可比；`form_widgets` 中规则外附加键新增 `contract` 字段，前端可忽略。已生成的包若需同步软删端点口径，重跑生成器即可。

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
