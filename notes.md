# notes.md — AI 协作者工作笔记

> 长期记忆：踩过的坑、确认过的做法，一条一行，新的放上面。
> 本仓开源：不写内部项目名、内部域名、密钥。

- HasOperator 已上移 Mooeen\Scaffold\Concerns\HasOperator（2026-07-16）；生成器直接引用共享 Trait，不再生成本地 stub。经 OperatorResolver 取 nullable 身份，无身份统一为 null。
- grep 找「代码习语」会因空白对齐假阴性：`option('force') === null` 用单空格 grep 会漏掉双空格对齐的 `option('force')   === null`（2026-07-09 全仓改 isForced 时方案与我同时漏掉第 8 处 CreateModelCommand:70，靠 review 逮到）。全仓替换某习语时：要么 grep 宽松模式（`option\('force'\)\s*===\s*null`），要么把命中数跟预期数对一下，对不上就换模式复核。
- plan-53 双路径(originCtx 三元 23 处)不再强收敛到 TargetContext：path 能干净参数化，但 namespace 前缀的 module-folder 插入散在多处下游、且 host 的 controller 路径随 app 变会逼 host context 存 app-keyed 嵌套 map——分支只是重定位进值对象、间接层更深，读得反不如现状 6 行 if/else 直白。2026-07-09 止损，维持现状（无新证据别重开）。
- 包目录的 `vendor/` 独立于宿主且默认没装；跑 pint/pest 前先在包目录 `composer install`。
- 本仓没有 phpstan/larastan 等静态类型检查；类型问题靠 Pest 测试 + 评审兜底，别去找不存在的配置。
- e2e 一律用 `npm run test:e2e:safe`（结束自动回滚宿主 scaffold 数据）；裸 `test:e2e` 会把宿主数据跑脏。
- Scaffold 自仓测试的 `vendor/charsen/moo-monitor-laravel` 可能是发布版而非相邻开发仓；涉及 Cloud 契约时必须临时用本地 Monitor 组合复跑，否则旧 `{ok,saved}` fake 会给出假绿。手动 Cloud push 要逐类型独立尝试，不能因一类 partial/retry `break` 阻断下一类；循环后统一汇总已确认/已隔离/失败事实，且仅在确有确认、隔离或本地回收时失效 summary 缓存。
- 同一 Host 多 `.env.XXX` 项目必须依赖 Monitor `^0.1.13`：YAML、cursor、partial ack、同步锁、回收范围与 scheduler 子命令的 `--env` 需整体隔离，不能只升级 Scaffold 页面编排。
- `CloudSync::sync()` 返回 `skipped=true` 时，`reason` 是用户可见契约；控制器必须透传真实原因（例如同类型同步锁竞争），不能统一误报为分类型开关关闭。
