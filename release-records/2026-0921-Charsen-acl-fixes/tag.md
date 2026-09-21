# 跨控制器 ACL key 归属与白名单污染修复（moo-scaffold 2.2.2 候选）

状态：**准备发版（未打 tag）** —— 两条修复已在 `dev` 与 `master`，工作区干净。

## 发布形态

- 本仓：待打 annotated patch tag **`2.2.2`** → `master` 顶点（含本记录）。
- **发版顺序**：本仓必须先于 `某个内部 Host` —— host 生产 pin 现为 `charsen/moo-scaffold ^2.2.1`，
  本批修复经 `moo:auth` 生成产物后才生效，host 生产 pin 需同步抬到 `^2.2.2`。
- 不包含：服务器部署；其它仓的发版由各仓自行记录。

## 包含范围（自 `2.2.1` 起 2 条修复）

- **白名单污染**：`buildActions()` 用 `$meta['action_keys']`（transform **目标**的 key）填白名单，
  于是别的 controller 的真实权限点被写进 whitelist，Gate 命中即放行 —— 实测 **12 个 key /
  90 个路由动作**对任何登录者恒真；`hasAction('ContractController::show')` 恒真会短路
  `ContractAclCheckTrait` / `ReceivePaymentTrait`，**按合同的数据范围 ACL 被整体绕过**。
  修法：写入前加不变式「任何被非白名单动作校验的 key 永不允许出现在白名单」，冲突时打印剔除清单。
- **跨控制器复用的 ACL key 未写入授权字典**：跨 controller 的 `transform_methods` 原走空分支，
  整组 key 不进 `config/actions.php`，授权页看不到也无从勾选（「查看应用」不可见 → 非 root
  进不去小应用内部）。修法：按 key 真实归属写回所属 controller，归属取自主循环登记的
  `aclTargetOwner` 索引（不自行反推 —— FQCN 与明文 key 的段口径不同，会算出伪控制器）。

## 下游需注意

- 修复的是**生成器**，下游必须重跑 `php artisan moo:auth <app>` 才会在产物中生效
  （重跑前请确认已无手工润色过的生成物，生成是内容驱动、会覆盖）。
- 白名单收敛后，原先「靠白名单放行」的动作会转为**需授权**：受影响 key 已由 host 侧单独
  补 `@acl` 或补齐授权，具体清单见 `某个内部 Host` 的发版记录。

## 验证

- 下游 `某个内部 Host` 实跑 `moo:auth admin`：白名单 160 → **148**，`whitelist ∩ actions` **12 → 0**；
  以「非 root 且无任何角色动作」的用户断言上述 5 个 key 的 Gate 判定由 `true` 变 `false`。
- 修复后重跑：`config/actions.php` 白名单键与字典键交集 **0**，字典树零误删。
- 本仓测试 **1138 passed**（失败数 188 与改前基线一致，均为既有环境问题）。
- 幂等：`moo:auth` 二次执行全部 `No changes`。

## 发版前剩余项

- [ ] 打 annotated tag `2.2.2` 并推送 `dev` + `master`，核对远端解引用。
- [ ] 通知下游 host 抬 `charsen/moo-scaffold` 生产 pin 至 `^2.2.2` 并重跑 `moo:auth`。
