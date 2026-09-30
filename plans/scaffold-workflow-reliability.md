# Scaffold 操作可靠性修复

用户已批准全面复盘提出的六项改进。本轮不改变改密后的会话有效性、写接口默认 ID、422/522 交互规则、生产只读或日志权限。

- 设计器合并待保存修改并串行发送;预览和迁移生成等待最新保存,预览过期时要求重看。
- 删除单表仅生成目标表迁移、推进目标表基线,不纳入其他表未确认修改。
- 保存/生成迁移后缓存刷新失败仍保留已落盘文件,通过 warnings 明确提示补救。
- 账号读失败维持鉴权拒绝;写入严格拒绝损坏内容,完整读改写串行化,原子写并限制 0600。
- 调试历史对 URL、参数、头脱敏;旧历史读取时处理并回写,不改原请求。
- 调试代理分流 query/body,兼容旧 `_proxy_params`;不增加 JSON/上传发送模式。

验证采用包内定向及全量 PHP/JS 回归、真实 path-repository Host 定向接口检查和用户明确授权的 E2E。本轮新增接口检查写入隔离临时目录；E2E 使用独占文件并由 safe runner 清理，出网请求使用 fake。全量测试按用户要求执行,不启用 `PEST_HOST_SCAFFOLD_PATH`。

实际验证结果：

- 全量 PHP：`composer test -- --compact --display-skipped`，1381 passed / 5964 assertions，3 skipped，18.68 秒。既有跳过项为 Testbench 不支持的 `moo:fresh` smoke 和下述两项迁移 fixture 缺失；没有失败。
- 全量 JS：`npm run test:js`，7 个文件、185 assertions 全部通过。
- 定向 PHP：120 passed / 515 assertions，2 skipped。跳过的是原 DesignerControllerTest 缺少既有迁移 fixture 的两例；本轮新增写入、缓存警告和单表迁移回归均实际执行。
- 定向 JS：保存流程、历史脱敏、设计器解包和通用响应契约 4 个文件，共 121 assertions 通过；不依赖 vendor。
- 真实 path-repository Host：11 项接口检查通过，包括认证、query/body 分流、停用立即拒绝、部分成功警告、单表删除落点和其他表基线保留。源 YAML、账号、缓存和 migration 均位于临时目录，未执行数据库迁移。
- `vendor/bin/pint --dirty --test`、修改脚本的 `node --check` 和 `git diff --check` 通过。UI 静态守卫 5 pass / 0 fail，保留两处原有内联脚本长度建议。

新增历史脱敏脚本随 public 发布；接入 Host 时须重新发布 Scaffold public 资源。

## Host E2E 与发版准备（2026-09-30）

本地真实 Laravel Host 通过 path repository 加载本任务分支，先同步本包 public 资源。使用 Playwright Chromium headless 和 `npm run test:e2e:safe`；测试账号文件、Session、缓存及日志在临时服务中隔离。既有账号凭据未匹配当前 Host，改用独立 E2E 管理员经真实登录表单登录，没有修改日常账号。该验证覆盖 Scaffold 业务流程，不代表 Host 全部领域模块的端到端验收。

- 首轮完整套件：58 例，49 passed / 8 failed / 1 skipped。失败为 Cloud 关闭导致按钮不显示、6 例文档编辑触发真实 429 限流、1 例账号用例继承管理员登录态而超时。
- 仅补跑失败项：Cloud 与计划编辑 4 例、发版日志编辑 3 例、账号 1 例均通过。账号单独等待限流窗口恢复后验证，不关闭或修改限流规则。
- 新增 API 历史浏览器回归 1 例通过：发送的 URL/认证头保留原值，新增历史和旧历史读取回写均脱敏。首次新增用例误假设所有接口都有 Token 输入框，改用页面提供的「新增 Header」后通过。
- 累计去重结果：59 例中 58 例通过，1 例 AI 外部实调明确跳过；未调用生产业务接口、Cloud 清理/外部同步或数据库迁移。
- 核心新增回归通过：在途保存合并、预览等待与过期拦截、删除 A 不纳入 B 的变更、部分成功警告、522 不重试、账号增改删、停用立即拒绝旧会话、日志菜单新窗口。
- 修正既有设计器测试的 4 处定位，使用稳定的 `#designer-table-key`，避免新增「改名」控件改变标签文本后超时。账号新 Context 显式使用空登录态。
- safe runner 一次遇到短暂 Git index 锁，随后锁已消失；回读 Host 工作区确认干净，无 schema/snapshot/migration 残留。结束后重建正常 Host Scaffold 缓存并清理本次隔离目录、服务，恢复原测试登录态文件。

待发布版本为 `2.2.12`，发布说明已从 Unreleased 收口。本版无数据库迁移、无新增依赖，保留 422/522、生产只读和日志权限语义。`composer.json` 在无旧 lock 的临时目录通过 `composer validate --strict`；本机 ignored 开发 lock 的陈旧提示未固化或更新。当前运行时代码归档包含新增历史脚本与相关资源，测试/plan 仍裁剪。

收尾回读：Host 工作区干净，三个变更运行时 JS 的发布副本与本包逐字节一致，独立服务端口已关闭、隔离目录已移除。发布线是当前 task HEAD 的祖先；对已提交修复执行 `git merge-tree --write-tree dev HEAD` 无冲突。新增 E2E 实际运行通过，全部工作区 diff（含新增测试文件）无空白错误；未因本轮仅测试/说明变化重复执行此前已通过的 PHP/JS 全量。

准备阶段双远程检查：Gitee/GitHub 的 master、dev 与 `2.2.11` peeled tag 一致；`2.2.12` / `v2.2.12` 均不存在。任务分支 `feat-scaffold-log-viewer` 在上版发布线之上仅有可靠性修复提交 `010d94c`，E2E 与发版说明改动待提交；准备阶段没有执行 commit/merge/tag/push 或部署。

## 小版本 Git 交付

用户随后明确要求「发小版本」，授权发布 `2.2.12`。范围为上述可靠性修复、已实际验证的 E2E 测试和发布说明；另一个 Git 管理工具的仓库详情布局反馈不属于本包，不列为本版修复。

提交本次测试/说明后，将本会话同一任务分支分别合入 dev 与 master；发布线可快进，dev 的独有提交均为历史集成 merge，预演无冲突。在显式检出的 master 上创建 annotated `2.2.12` tag，分别推送 Gitee/GitHub 的 master、dev 与本次 tag，回读远程对象和 peeled tag、ahead/behind。验证沿用上述已通过结果，无运行时代码新增，不重复执行全量或 E2E。Git 交付不执行服务器部署、生产迁移或修改 Host 生产 Composer 约束。
