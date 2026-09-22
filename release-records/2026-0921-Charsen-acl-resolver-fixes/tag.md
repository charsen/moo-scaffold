# AclActionResolver 生成期缺陷修复（moo-scaffold 2.2.3 候选）

状态：**准备发版（未打 tag）** —— 修复已在 `dev`；发版时需合入 `master` 并推送。

## 发布形态

- 本仓：待打 annotated patch tag **`2.2.3`** → `master` 顶点（含本记录）。
- **本批会改变下游 ACL 产物**（见下「下游需注意」），故 6 个 host 都需重跑 `moo:auth` 并跟一次补丁版本。
- 不包含：服务器部署；其它仓的发版由各仓自行记录。

## 包含范围（三条修复）

1. **boot 前未设 `$this->method`**：`Foundation\Controller::$method` 是未初始化 typed property，
   只在运行期 `callAction()` 赋值；生成期直接 `boot()` 会让任何在 boot 里读它的控制器抛
   `must not be accessed before initialization`，被 `catch (Throwable)` 吞成「回退 key」。
   实测 `PersonnelOptionController`（boot 校验流程定义的设计动作授权）三个动作全部落成
   「无标签白名单」，产物对授权事实撒谎。修：boot 前反射设成本次解析的动作名。
2. **领域授权在生成期误伤**：`bootWithoutAuthorization()` 只关 `scaffold.authorization.check`，
   管不到控制器**直接调 Gate** 的领域校验。生成期无登录用户，判定必然失败并中止扫描。
   修：容忍 boot 抛出的 `AuthorizationException`（transform 赋值在抛错前已完成）；其它异常仍回退。
3. **跨控制器 transform 的 key 算错**：`formatAclName()` 原在起源控制器实例上调用，目标动作被按
   **起源**命名空间解析。实测 `PersonnelOptionController -> ProcessDefinitionController::update`
   产出 `admin-process-mooeen-process-http-controllers-admin-process-definition-update`，与目标
   自己运行期校验的 `admin-process-process-definition-update` 不一致，**勾了也不生效**。
   跨控制器是框架既定支持；错的是 key 算法。修：改用静态 `Controller::aclPlainKey($target)`。

## 下游需注意

- 修复会**改变各 host 的 ACL 产物**：原先静默回退的动作恢复真实授权关联，跨控制器目标的 key
  归位。**发版后每个 host 都必须重跑 `php artisan moo:auth <app>` 并核对键级 diff**，
  再跟一次 host 补丁版本（否则线上产物仍带旧口径）。
- 受影响的典型：`PersonnelOptionController`（`designer` / `conditionOptions` /
  `simulationSubjects`）从「无标签白名单」变为继承 `ProcessDefinitionController` 的
  update / publish / simulate。

## 验证

- `tests/Feature/Support/AclActionResolverTest.php` **5 passed / 19 断言**。
  其中原断言 `aclresolverprobecontroller-store`（FQCN 未分词）是**错误口径**，已同步为
  `acl-resolver-probe-store` 等正确值；`key` / `target_keys` 断言改为 md5 口径（三处 md5 独立复算一致）。
- 本仓测试失败数与改前基线一致（既有环境问题：`no such table: sessions`），无新增失败：
  逐条 A/B 验证「新增失败」实为测试标题在输出中被截断所致的比对噪声。
- 下游实测（某个内部 Host 重跑 `moo:auth`）：`whitelist ∩ actions` 保持 0；原先落成「无标签白名单」
  的动作恢复父权限关联；字典随选项源合并 767 → 763。

## 发版前剩余项

- [ ] 合入 `master`、打 annotated tag `2.2.3`、推送 `dev` + `master`、核对远端解引用。
- [ ] 通知 6 个 host 抬 `charsen/moo-scaffold` 到 `^2.2.3` 并重跑 `moo:auth`。
