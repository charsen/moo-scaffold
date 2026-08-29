# AGENTS.md

本文件适用于整个 `moo-scaffold` 仓库。规则冲突时依次服从系统/用户指令、当前代理规则、用户批准的任务方案、`NOTES.md`、`docs/` 和历史 plan。文档必须由当前代码、stubs、测试及消费方 host 行为校验。

## 开工顺序与记忆

1. 先读 `NOTES.md`，再读 `README.md`、`docs/overview.md` 和任务对应的 `docs/guide/*`。
2. schema/codegen 任务再读 `docs/yaml-style.md`、相关 `Command`、`Generator`、`stubs` 与测试；Cloud 契约同时核对当前 `moo-monitor-laravel`。
3. 改文件前完整阅读目标文件和直接调用链。机械性、零语义且范围明确的小修可直接实施；非琐碎或涉及生成覆盖、宿主数据、公共契约、发布的改动先列计划并取得用户批准，范围、覆盖行为或宿主数据风险变化时重新确认。

- `NOTES.md` 是已验证、可复用且不适合写成稳定规则的长期记忆。新增内容一条一项、放在合适分组，避免进度日志、猜测和固定测试数量。
- 本仓公开；notes、文档、测试和注释不得出现内部项目名、内部域名、真实账号或密钥。旧结论被证伪时修订原条目。

## 项目定位

- 本仓是 schema 驱动的 Laravel 代码生成器和开发辅助后台，不是独立 Laravel 应用，自身没有业务表或根 `artisan`。
- 两大支柱是 `src/Command` + `src/Generator` 的 `moo:*` codegen，以及 `/scaffold/*` 的数据库设计器、API 调试器、ACL、配置和文档中心。
- YAML 是结构设计真相源；`stubs/` 与 `src/Foundation/` 是生成代码规范。修改模板等同修改所有后续 host/扩展包的编码约定，必须核查下游形态。
- Web 写能力只用于开发环境；生产环境一律只读。CLI 的 local gate 和 Web 的 `EnforceScaffoldWritable` 不得被新入口绕开。
- 运行时异常/慢 SQL 采集由 `moo-monitor-laravel` 提供；Scaffold 只负责编排和展示，不复制采集、缓冲或 Cloud 同步实现。

## Schema、快照与迁移

- schema YAML、`.snapshots/{Schema}.yaml` 和 migration 是一次数据库设计变更的原子三件套。设计器保存、命令生成和多人同步都要保持三者闭合。
- `moo:fresh` 只解析/刷新缓存，不等于数据库已迁移；`moo:db:audit` 只读对账 YAML 与真实 DB，不擅自修正任何一方。
- suspected rename 必须显式确认；不能把 drop+add 静默当 rename，也不能在含糊时推进 snapshot 掩盖风险。
- migration 默认一表一文件。renameColumn、纯数据迁移、复杂索引等边角可手写，随后用当前 snapshot 流程重锚并验证空库与存量升级。
- 删除、改名、类型、nullable/default、unique/index 变化都是数据迁移，不只生成 PHP 文件；设计器和 CLI 应保持同一 diff/writer 语义。
- YAML dump 需遵守 `docs/yaml-style.md`：保留文件/段注释、稳定 key 顺序和字段白名单，避免无意义全文件格式漂移。

## 生成边界

- 先分清可重复覆盖、仅首次生成和手写深化区；命令的 `--force` 语义必须精确，禁止为“方便”扩大覆盖范围。
- Model trait、Enum 等再生成资产应由 schema 推导；Model/Controller/Request/Resource 等若含业务代码，重生成前先检查现有保护和 diff。
- `moo:free` 是编排器，不另实现生成逻辑；各阶段应调用相同 generator/service，且单阶段失败、跳过、warning 的契约保持现有区别。
- `-t/--table` 只影响文档明确承诺的阶段。不能出现“单表代码 + 全库迁移”等自相矛盾行为。
- host schema 与扩展包 schema 按 origin 分流：包的源资产落包仓，聚合缓存/ACL 等落 host；当前不支持的包前端或测试生成要 fail-fast/明确跳过，不偷偷写进 host。
- 生成命令必须在接入本包的 host Laravel 根运行；本仓 Testbench fixture 只验证包逻辑，不等于真实 host 生成闭环。

## Web 后台与安全

- `/scaffold` 是研发工具而非业务后台。账号、配置和 schema 等写操作必须同时满足环境、授权、CSRF、路径和输入校验。
- 生产只读不只是隐藏按钮：后端写路由和文件/数据库 writer 必须拒绝执行。
- 配置 UI 对密码、token、secret 只显示安全占位，不把明文带入 HTML、日志、测试快照或 diff。
- Blade/Alpine 继续遵守 CSP-safe 约束；前端现有 jQuery/Alpine 结构不因单个页面引入新的大型框架。
- 修改 public Sass 后运行 `npm run build:css` 并提交编译产物；不要手改生成 CSS。

## 公共契约与兼容性

- Composer 支持面以当前 `composer.json` 为准；不要把本仓主要测试的 Laravel/PHP 版本误写成全部运行时矩阵。
- `HasOperator`/`OperatorResolver` 属跨包身份契约，保持 nullable 与 host 绑定语义；Scaffold 不依赖某个 host 的 Personnel 模型。
- Route 宏、BaseController、Filter、Resource、表单 widget、ACL key、API YAML 和语言包都是下游编译接口。改公开签名或默认输出前检索已知 host/包消费者。
- Cloud intake 的逐条回执、环境 scope、锁/cursor/ack 由 Monitor 契约约束；联调时本仓 vendor 可能是旧发布版，必须核对实际组合，不能把旧 fake 的绿灯当新契约已验证。

## 验证与交付

- 文档-only 至少运行 `git diff --check` 并核对链接、命令和版本。
- PHP 改动先跑目标 Pest，再运行 `vendor/bin/pint --dirty --test` 与 `composer test`。需要格式化时再运行写入式 Pint 并复核 diff。
- `/scaffold` UI 改动运行 `npm run test:e2e:safe`，不得用会污染宿主 scaffold 数据的裸 `test:e2e`；同时说明使用的 host、浏览器和未覆盖环境。
- schema/codegen 变更需用 fixture 和真实 path-repository host 验证生成落点、重复运行、force 边界及 diff；涉及扩展包时检查包仓而非只看 host。
- 不主动 commit、push、bump、CHANGELOG、tag 或发布。用户要求提交前展示完整 diff 和验证结果并获明确确认；日常提交可进 `master`，版本与 tag 仍是独立发版动作。
