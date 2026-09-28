# TODOS

本文件集中记录当前尚未完成且可执行的项目待办。复杂方案应链接到正式 plan；完成后及时勾选或清理。

## 通用字段交付待办

- [x] **已完成（2026-09-20 逐项核验）**。四步发布顺序全部到位：① scaffold 侧已随稳定版 `2.1.25` 发布
  （tag 已推两个远程）；② 两个消费包均已把最低依赖收紧到 `^2.1.25`；③ 消费包已发布
  （mini-app `0.1.9` / process `0.2.11`，tag 已推）；④ 宿主三份 composer manifest 合规
  （`ComposerProfilesTest` 6 passed）、SPA 控件类型注册表校验一致（18 / 18）。
  ⑤ 宿主**生产**档已把 mini-app / process 的 pin 由 `^0.1.8` / `^0.2.10` 显式抬到 `^0.1.9` / `^0.2.11`
  （caret 本已覆盖最新版，属「显式钉住已验证版本」的取舍），并经宿主侧
  `fix-production-pin-moo-packages` 分支合并进其 `dev` 与 `master`。
  仅余**一项**非阻塞遗留（不是本仓能收的）：
  1. mini-app 的 `repositories.moo-scaffold` 仍是 `path` sibling（`../moo-scaffold`），
     脱离同级目录无法独立 `composer install` —— 已由其自身 `TODOS.md` 登记为发布/CI 决策。
     （process 侧已是 `vcs` 形态：2026-09-20 已在无锁文件、无同级目录的干净克隆上实测
     `composer install` 通过，解析出本包 `2.2.0`，来源即其声明的 vcs 仓库。）

## 扩展包骨架规范对齐（2026-09-28）

**已批准方案（仓库内副本）**：[`plans/moo-package-structure-alignment.md`](plans/moo-package-structure-alignment.md) ——
  盘点结论（A/B/C/D 分级）、实施计划 Step 1–5 与「边界与不做」都在那里；本节的执行状态以本文件为准。

**接手复核（2026-09-28）**：同级目录出现了第 30 个目标 `moo-<name>`（`charsen/moo-<name>`，`feat-sequence`
  分支尚无任何 commit、全部 untracked），**另会话正在从零开发**，其 MISS 数在实时下降（15 → 12，别当基线）。
  按用户指示**整仓剔除**：脚本新增 `DEFERRED_TARGETS`，理由与移除条件随报告「跳过」段与 `--json.skipped` 打印
  （不静默跳过），**移除条件＝该包首次 commit 落地**；显式 `--package=moo-<name>` 仍可单查它的当前状态。
  剔除后 `--fail-on-drift` **exit=0**，本轮 **29 目标 0 MISS / 0 STYLE-DRIFT**。

**收口状态（2026-09-28）**：原本全部改动都裸躺在 24 个仓的发布基线（`main`/`master`）工作区里 —— 违反
  「发布基础分支不直接开发」。已逐仓 `fetch` 复核基线最新后，从基线各切出一个 `feat-package-skeleton-alignment`
  并提交（显式路径暂存，工作区已干净、各领先 `origin/<基线>` 1–2 个提交）。**未推送、未合入 `dev`/`master`**；
  将来发布时把**这一个分支分别合入两条线**，两条线互不经过对方。5 个他会话仓见下方待办。
  **第二批（同日）**：`.gitattributes` 的 dist 裁剪补齐 `/plans` 与 `/TODOS.md` —— 此前基准版注释写着「工具/文档不进包」，
  实测 `plans/`（4 仓 9 文件）与 `TODOS.md`（23 仓）仍会发进消费方 `vendor/`；22 个带该基准文件的基线仓各追加 1 个提交。
  逐仓用 `git archive HEAD` 回读：归档不再含 `plans/`、`TODOS.md`，且 `src/config/routes/database/lang/scaffold` 未受影响。
  **注意两条边界**：① `export-ignore` 只在「按 tag 装 dist」时生效，本地 `path`+`symlink` 联调与本机三个 host 的
  `vendor/charsen/*` 都看不到效果（写错只会在测试服/生产暴露）；② 属性取自**被归档的那棵树**，所以必须进提交/进 tag ——
  验证工作区改动要用 `git archive --worktree-attributes`。
  **第三批（同日）**：`moo-scaffold` 与 `moo-engine-skeleton` 也各起一份（同一分支上追加提交）——
  前者原文件只有 `*.ai binary`（另一种用途），今回归档实测 **610 → 422** 个文件；后者原本没有 `.gitattributes`，新增后 **232 → 223**。
  **两处刻意例外，别当遗漏**：① `moo-scaffold` 的 `docs/` 与 `tools/` 保留 —— `docs/` 是 host 文档中心的包文档源
  （`src/Support/DocsRepository.php:50` 读 `$pkg` base_path 下的 `docs/`），裁掉等于删掉每个 host 文档中心里的本包文档，
  而 `docs/package-skeleton.md` 又指向 `tools/audit-package-structure.php`；② `moo-engine-skeleton` 保留
  `NOTES.md`/`TODOS.md` —— host 骨架要给新项目**继承**协作文档，与扩展包裁剪口径相反（已在两份文件的注释里写明理由）。
  `moo-engine-skeleton` 是 `type=project`，`git clone` 安装时该文件完全不生效，只影响 `create-project` / 归档下载。

判据已固化为 `tools/audit-package-structure.php`（只读闸门，`--fail-on-drift`），规模口径见
`docs/package-skeleton.md`。**本轮只做非破坏性补齐**；下列事项需先确认设计意图或同步消费方，未动。

- [x] **3 个他会话仓已提交（挂在他的任务分支上，经用户显式许可）**：`moo-<name>`、`moo-<name>`
  （同处 `fix-category-business-status`）、`moo-<name>`（`fix-business-code-prefixes`）各一笔独立提交
  （`edeb59d` / `aea496a` / `8c866d0`，message 统一 `chore：对齐扩展包骨架规范`）。
  **为什么不再等**：那 3 个文件此前是**无保护的未暂存改动**，而对应会话正在逐笔提交，下一次 `git add -A` /
  `commit -a` 就会把它们卷进一笔业务提交（不可追溯、无法单独回滚）。提交时逐仓断言「暂存区恰好只有这 3 个路径」，
  未带走他们的业务改动（`moo-<name>` 脏项 25 → 22，其余文件原样保留）。
  ⚠ **这 3 笔提交混在他会话分支上**：会随他们的分支进 `dev`/发布线，而他们的发版记录不会提到它；
  他们若 `rebase`/`reset` 该分支，提交可能丢（改动机械，可重放）。

- [x] **5 个他会话仓全部提交完毕（各自挂在该仓既有任务分支上）**：前三个见上；`moo-<name>`（`b645f9f`）、
  `moo-<name>`（`b45c588`）按用户指示「commit + 在现有分支继续」补上 —— ⚠ 这使
  `fix-shared-sequence` 的**首个提交**成了骨架对齐提交（原计划避开的正是这点），未推送，
  `git reset --mixed HEAD~1` 可干净退回。
  ⚠ **我在这条上犯过一次错，记下来**：本节早就写明「补的时候用已更新的基准版，别照抄工作区里那份旧的 15 行版」，
  我第一遍仍按工作区原样提交了这 5 仓（13 条旧版、缺 `/plans` 与 `/TODOS.md`），复查提交树时才发现，
  随后 5 仓各补一笔 `chore：dist 裁剪补齐 plans/ 与 TODOS.md` ⇒ 现均为 15 条基准版，
  `git archive` 已排除 `plans/`、`TODOS.md`。
  **教训**：写在文档里的交接注意事项，落盘前要当**验收清单**逐条勾，不能当背景说明读过就算 ——
  否则同一份文档既是判据又被自己无视，比没写更坏。

- [x] **已完成**：补齐 `moo-feedback/AGENTS.md`（该包唯一缺失的项目指令文件）；补齐 5 个包的 `CLAUDE.md`
  （contract / feedback / meeting / richtext / upload，另 24 份改为纯入口）；`pint.json` **8 份**偏离
  （B1 1 + B2 3 + B3 4）已归一为 canonical 规则集，其余 21 份本就一致；`.gitattributes` **27 份**落实
  「dist 裁剪但保留 `/scaffold`」（23 份新增 + 4 份既有归一：process / radar / richtext / system；
  `moo-scaffold` 既有未改、`moo-engine-skeleton` 无此项）。其中 **24 份为同一基准版**，
  `moo-<name>`（+`/tools`）、`moo-system`（+`/HANDOFF.md`）、`moo-<name>`（+`/.codegen`、`/tools`）
  是在基准上按本仓增补 `export-ignore` —— **属有意定制，不是漂移，别再「统一」掉**。
  审计结果：本轮 **29 目标 0 MISS / 0 STYLE-DRIFT**
  （当前工作区第 30 个目标 `moo-<name>` 已整仓剔除，见上方接手复核）。

- [ ] **CI 骨架（阻塞，20 个包缺 `.github/workflows/`）**。前置不是「加 workflow」，而是
  **「脱离同级目录能 `composer install`」**：13 个包的 `repositories` 声明 sibling `path`（`../moo-*`），
  GitHub Actions 只 checkout 本仓 ⇒ 私包解析不到（也不在 Packagist）；已有 `quality.yml` 的 6 个包同样带 path 依赖、
  且 workflow 无 ssh-agent 步骤，另有包用 `git@gitee.com:...` 的 vcs 源 ⇒ **那几份多半本来就是红的**。
  与上面「mini-app 仍是 path sibling」是同一条根因。**收口顺序**：先把受影响包的 manifest 改成 vcs 形态
  （`moo-<name>` 的样板）+ 在 workflow 注入部署密钥，再谈批量补 CI。范本：`moo-<name>/.github/workflows/quality.yml`。

- [ ] **既有 pint 违规（改前就红，非本次引入）**：`moo-<name>` 35 处 / `moo-<name>` 2 处 /
  `moo-<name>` 1 处；**本仓自身 2 处**（`tests/Feature/Concerns/HasOperatorContextTest.php`、
  `tests/Feature/Concerns/OperatorResolverTest.php`，`class_attributes_separation`）。
  已用「提交态配置 A/B」与「文件未被本次修改」双重证实与本次 `pint.json` 归一无关，属独立过堂。

- [ ] **LAYOUT 待议（改动即破坏 namespace，需同步消费方与 codegen 重生成）**：
  7 包把 model trait 放在 `src/Models/Concerns/`（`moo-<name>` 2 / `moo-<name>` 2 / `moo-<name>` 2 /
  `moo-<name>` 2 / `moo-feedback` 1 / `moo-<name>` 2 / `moo-<name>` 2），规范位置是 `src/Models/Traits/`
  （codegen 硬编码 emit `use {ns}Traits\...`）；`moo-<name>/src/Concerns/HasRichTextFields.php` 同族；
  `moo-<name>/src/Models/Concerns/` 是空残留（可直接删）；
  `moo-<name>/src/Http/Requests/Business/`（19 个 Request）与 `moo-<name>/src/Http/Requests/{WeWork,Export}/`
  是模块段，规范按 `Requests/<Controller>/` 分组。

- [ ] **NAME 待议（判断题，可能是有意的）**：`moo-monitor-laravel` 用 config stem `moo-monitor`、
  namespace `Mooeen\Monitor`、provider `MonitorProvider`、psr-4 target 缺尾 `/` —— 但 `moo-monitor-vue` 是姊妹前端包，
  `monitor` 可能是**刻意共享的产品 stem**，需先确认；`moo-scaffold` 的 config stem `scaffold` / provider
  `ScaffoldProvider` / 发布标签 `config`、`public` 同理，且它是全生态宿主的既有契约，改动风险最高。

- [ ] **环境与接线待议**：`moo-<name>` 的 env 变量 `RADAR_ADMIN_MIDDLEWARE` 漏 `MOO_` 前缀；
  10 个包的后台中间件组默认值仍是 `admin`（banner 等 13 包已是 `moo-<name>`），而 host 只注册了
  `moo-system` / `moo-upload` / `moo-feedback` 三组 —— 与 host 自身注释要求的「逐包独立命名组」冲突，
  收口需 host 逐包注册并做安全验证（匿名 401 / 无 ACL 403 / 授权成功）。

- [ ] **`moo-<name>` 测试形态**：走 `tools/bootstrap.php` + `tests/bootstrap.php` 驱动，无 `TestCase.php` / `Pest.php`，
  另有 `.codegen/` 与 `tools/{bootstrap,generate,metadata}.php`。需确认是否有意为之，再决定是否收敛到家族统一样式。

- [ ] **host `moo-engine-skeleton`**：三份 manifest 的私包 repository 类型与版本分流**正确**，但
  `require-dev` 与 `scripts` 存在规范外差异（local 多 pest / sail / dump-server 与 `setup`、`dev`、`lint`，
  test / production 只有 `clear-all`）；仓库根无 `CHANGELOG.md`；`moo-feedback` 已被 require 且有 repository 条目，
  却未进 `extra.moo-private-packages`（`PRIVATE-COMPOSER-PACKAGES.md:63` 有说明，需复核是否仍成立）。
