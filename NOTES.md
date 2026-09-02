# NOTES.md — AI 协作者工作笔记

> 长期记忆：踩过的坑、确认过的做法，一条一行，新的放上面。
> 本仓开源：不写内部项目名、内部域名、密钥。

- 2026-09-02，症状：`moo:api {app} -a` / `moo:auth` 在接口毫无变化时仍重写产物，一次跑出上百个只有时间戳和尾空格差异的假 diff。根因：`CreateApiGenerator::syncControllerFile()` 用整文件**字节**比对判 unchanged，而空 `code` 的尾空格在 `26275dab` 被去掉，所有历史 yaml 恰好差这 1 个字节；`UpdateAuthorizationGenerator` 更是无条件 `put()` 三类产物。解法：全部改语义比对——API schema 比 `Yaml::parse()` 后的结构（注释与排版不参与），ACL 文档比剔除 `generated_at` / `generated_by` 后的内容，PHP 产物比 `return` 的数组本身。**别再往 unchanged 判定里塞字节比对**：生成器改一次输出格式就会把全仓产物刷成假更新。解析失败一律按「有变化」重写，坏文件不能被静默跳过；要立刻统一排版走 `--force`。
- 2026-09-02，配套坑：给 `config/actions.php` / `lang/{lang}/actions.php` 加生成戳注释头之后，**光靠内容比对永远补不上头部**——比对只看数组，看不见注释，老产物会一直判 unchanged。得额外加一个「缺头部也算有变化」的条件让它补写一次；这类一次性格式迁移必须是**有界的显式判据**（有没有那行标记），不能退回成字节比对。头部形态按 host Pint 口径写：`<?php declare(strict_types=1);` 同行 + 空行 + `/* */` + 空行 + `return`，比旧的裸 `<?php` 少触发 `declare_strict_types` 与 `blank_line_before_statement`；剩下的 `trailing_comma_in_multiline` / `binary_operator_spaces` 来自 VarExporter 输出，是改动前就有的，别去追。

- 2026-08-18，症状：同时声明 `field` 数组规则与 `field.*` 子项规则时，生成的聚合表单控件会被子项再次覆盖，出现 nullable 字段被标必填、上传控件类型或显式默认值丢失，并把 `string` 等元素规则错误应用到整个数组。根因：`getFrontendRules()` 与 `formatFormConfig()` 都把 wildcard 键折成父键，后写子项覆盖父控件。解法：父规则存在时不再为 wildcard 生成同名控件或合并其前端规则；wildcard 只保留多选信号，父规则不存在的历史用法继续兼容。
- 2026-08-13，症状：控制器已补 `@controller_name` 和 action DocBlock 后，重跑 `moo:api --sync-names` 仍可能保留 YAML 的空控制器名，且无 DocBlock 的方法会被同步成 `index/show`。根因：控制器名称没有纳入 `--sync-names`，空字符串被当作已有值；action 又把方法名兜底与 DocBlock 真值混为一体。解法：空名称统一按缺失回填，`--sync-names` 同步 controller/action；无方法注释时保留既有非空文案，只在新 action 上使用方法名兜底。
- 2026-08-13，症状：`moo:free {app}` 虽已选择一个端，Controller/Resource/Test 生成器仍遍历 schema 的全部端，且模板名由 app key 拼接，新增 Web/RPA 会漏生成或找不到 stub；根因：app 同时被误当作 API 同义词、目录名和模板策略。解法：controller 配置统一进入 AppTargetRegistry，显式声明 profile/path/stub/route_mode；默认端用 admin/mobi/web，`manual` 端不自动写 iResource，host schema 未注册端直接报错，包 schema 的端契约由包自身管理。
- HasOperator 已上移 Mooeen\Scaffold\Concerns\HasOperator（2026-07-16）；生成器直接引用共享 Trait，不再生成本地 stub。经 OperatorResolver 取 nullable 身份，无身份统一为 null。
- grep 找「代码习语」会因空白对齐假阴性：`option('force') === null` 用单空格 grep 会漏掉双空格对齐的 `option('force')   === null`（2026-07-09 全仓改 isForced 时方案与我同时漏掉第 8 处 CreateModelCommand:70，靠 review 逮到）。全仓替换某习语时：要么 grep 宽松模式（`option\('force'\)\s*===\s*null`），要么把命中数跟预期数对一下，对不上就换模式复核。
- plan-53 双路径(originCtx 三元 23 处)不再强收敛到 TargetContext：path 能干净参数化，但 namespace 前缀的 module-folder 插入散在多处下游、且 host 的 controller 路径随 app 变会逼 host context 存 app-keyed 嵌套 map——分支只是重定位进值对象、间接层更深，读得反不如现状 6 行 if/else 直白。2026-07-09 止损，维持现状（无新证据别重开）。
- 包目录的 `vendor/` 独立于宿主且默认没装；跑 pint/pest 前先在包目录 `composer install`。
- 本仓没有 phpstan/larastan 等静态类型检查；类型问题靠 Pest 测试 + 评审兜底，别去找不存在的配置。
- e2e 一律用 `npm run test:e2e:safe`（结束自动回滚宿主 scaffold 数据）；裸 `test:e2e` 会把宿主数据跑脏。
- Scaffold 自仓测试的 `vendor/charsen/moo-monitor-laravel` 可能是发布版而非相邻开发仓；涉及 Cloud 契约时必须临时用本地 Monitor 组合复跑，否则旧 `{ok,saved}` fake 会给出假绿。手动 Cloud push 要逐类型独立尝试，不能因一类 partial/retry `break` 阻断下一类；循环后统一汇总已确认/已隔离/失败事实，且仅在确有确认、隔离或本地回收时失效 summary 缓存。
- 同一 Host 多 `.env.XXX` 项目必须依赖 Monitor `^0.1.13`：YAML、cursor、partial ack、同步锁、回收范围与 scheduler 子命令的 `--env` 需整体隔离，不能只升级 Scaffold 页面编排。
- `CloudSync::sync()` 返回 `skipped=true` 时，`reason` 是用户可见契约；控制器必须透传真实原因（例如同类型同步锁竞争），不能统一误报为分类型开关关闭。
- 【坑】`Collection::putMore` / `default` / `forgetMore` 三个宏历来由**各 host 的 `AppServiceProvider::registerCollection()` 手抄注册**，scaffold 自己不注册；2.1.7 前 `FormWidgetCollection` 内部依赖它们，导致「scaffold 的类反过来要求 host 抄一份」——漏抄即 `BadMethodCallException`，脱离 host 的包测试里 form_widgets 链路整条跑不起来。2.1.7 已改自包含（`data_set`/`data_forget`），`tests/Feature/Foundation/FormWidgetCollectionTest.php` 的 beforeEach 显式断言「宏不存在」，把不许依赖回去钉成回归。
