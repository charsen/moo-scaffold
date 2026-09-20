# TODOS

本文件集中记录当前尚未完成且可执行的项目待办。复杂方案应链接到正式 plan；完成后及时勾选或清理。

## 通用字段交付待办

- [x] **已完成（2026-09-20 逐项核验）**。四步发布顺序全部到位：① scaffold 侧已随稳定版 `2.1.25` 发布
  （tag 已推两个远程）；② 两个消费包均已把最低依赖收紧到 `^2.1.25`；③ 消费包已发布
  （mini-app `0.1.9` / process `0.2.11`，tag 已推）；④ 宿主三份 composer manifest 合规
  （`ComposerProfilesTest` 6 passed）、SPA 控件类型注册表校验一致（18 / 18）。
  仅余两项**非阻塞**遗留（都不是本仓能收的）：
  1. 宿主**生产**约束已拍板把 mini-app / process 的 pin 由 `^0.1.8` / `^0.2.10` 显式抬到
     `^0.1.9` / `^0.2.11`（caret 本已覆盖最新版，属「显式钉住已验证版本」的取舍）；改动已落在
     宿主仓工作区并通过其合规测试（`ComposerProfilesTest` 6 passed + 三份
     `composer validate --strict --no-check-publish` 均 valid），尚待宿主侧分支提交与合并。
  2. mini-app 的 `repositories.moo-scaffold` 仍是 `path` sibling（`../moo-scaffold`），
     脱离同级目录无法独立 `composer install` —— 已由其自身 `TODOS.md` 登记为发布/CI 决策。
     （process 侧已是 `vcs` 形态：2026-09-20 已在无锁文件、无同级目录的干净克隆上实测
     `composer install` 通过，解析出本包 `2.2.0`，来源即其声明的 vcs 仓库。）
