# Former `input-number` 类型同步（moo-scaffold 2.2.15 候选）

状态：**准备发版（未打 tag；本仓改动尚未提交/合入）** —— 本轮只准备，不新增 commit/merge/tag/push。

## 发布形态

- 本仓：待打 annotated patch tag **`2.2.15`** → `master` 顶点。
- **下游需注意**：本仓只把 `input-number` 加进 `FormWidgetTypes::FORMER`（调试器可识别清单 + 与下游注册表同步），**不改运行时**。某个内部 Host 按配套交付惯例把本包约束抬到 `^2.2.15`（`^2.2.11` 本已能解析到本版本，抬约束是为把「后端可下发 widget type 清单」钉到含 `input-number` 的版本）。
- 不包含服务器部署；下游 Host / Admin 由各自仓记录。

## 背景与改动

某个内部 Host 的后台筛选栏字段由后端下发 `type = input-number`（带 `min` / `max` / `step`），但下游 `@mooeen/scaffold` 的 former 注册表当时没有该类型 → 未知类型统一走**只读兜底**，字段只能看不能改。

- 本仓：`Support\FormWidgetTypes::FORMER` 增加 `input-number`，并把它登记为「只在 `FORMER`」的既成差异（调试器预览暂画不出、显示占位框，与 `text-amount` 同类）；`FormWidgetTypesTest` 同步补断言。
- 下游 Admin（同批）：`@mooeen/scaffold` 注册 `'input-number' → antdv InputNumber`；`getComponentProps` 会展开字段，`min` / `max` / `step` / `default` 直接生效。

## 验证

`FormWidgetTypesTest` 8 项 / 30 断言通过。本仓改动不改运行时，未跑全量测试、未部署。

## 正式发版下一步

`master` 顶点打 annotated tag `2.2.15`，推送 **origin（Gitee，canonical）与 github（公开镜像）两个远端** 的 `master` 与 tag，并逐远端回读核验。
