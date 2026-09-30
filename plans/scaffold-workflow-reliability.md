# Scaffold 操作可靠性修复

用户已批准全面复盘提出的六项改进。本轮不改变改密后的会话有效性、写接口默认 ID、422/522 交互规则、生产只读或日志权限。

- 设计器合并待保存修改并串行发送;预览和迁移生成等待最新保存,预览过期时要求重看。
- 删除单表仅生成目标表迁移、推进目标表基线,不纳入其他表未确认修改。
- 保存/生成迁移后缓存刷新失败仍保留已落盘文件,通过 warnings 明确提示补救。
- 账号读失败维持鉴权拒绝;写入严格拒绝损坏内容,完整读改写串行化,原子写并限制 0600。
- 调试历史对 URL、参数、头脱敏;旧历史读取时处理并回写,不改原请求。
- 调试代理分流 query/body,兼容旧 `_proxy_params`;不增加 JSON/上传发送模式。

验证采用包内定向及全量 PHP/JS 回归和真实 path-repository Host 定向接口检查,本轮新增测试与 Host 检查的业务写入均落隔离临时目录;既有回归使用原有 fixture,出网请求使用 fake。全量测试按用户要求执行,不启用 `PEST_HOST_SCAFFOLD_PATH`。未运行 E2E,未合并或发版。

实际验证结果：

- 全量 PHP：`composer test -- --compact --display-skipped`，1381 passed / 5964 assertions，3 skipped，18.68 秒。既有跳过项为 Testbench 不支持的 `moo:fresh` smoke 和下述两项迁移 fixture 缺失；没有失败。
- 全量 JS：`npm run test:js`，7 个文件、185 assertions 全部通过。
- 定向 PHP：120 passed / 515 assertions，2 skipped。跳过的是原 DesignerControllerTest 缺少既有迁移 fixture 的两例；本轮新增写入、缓存警告和单表迁移回归均实际执行。
- 定向 JS：保存流程、历史脱敏、设计器解包和通用响应契约 4 个文件，共 121 assertions 通过；不依赖 vendor。
- 真实 path-repository Host：11 项接口检查通过，包括认证、query/body 分流、停用立即拒绝、部分成功警告、单表删除落点和其他表基线保留。源 YAML、账号、缓存和 migration 均位于临时目录，未执行数据库迁移。
- `vendor/bin/pint --dirty --test`、修改脚本的 `node --check` 和 `git diff --check` 通过。UI 静态守卫 5 pass / 0 fail，保留两处原有内联脚本长度建议。

新增历史脱敏脚本随 public 发布；接入 Host 时须重新发布 Scaffold public 资源。
