# 11 · 多端 git 同步

用 git 在多端同步 scaffold 的可入库数据（API 发布历史 / 账号 / ai.yaml）。runtime 错误 / 慢 SQL 走云端（见 [`16-cloud-push.md`](16-cloud-push.md)），todo 走云端 Chrome 扩展,均**不在** git 同步范围。

> **同步脚本不随包分发。** 历史上的 `scaffold-sync.sh`（git 编排：commit / pull-rebase / push + cron）已从本包移除，由各宿主项目自行维护副本。本包只保留语义合并命令 `moo:scaffold:merge-yaml`（见 §3），脚本怎么编排、多久 cron 一次由下游自己定。

## 1. 什么可入 git 同步

| 子集 | 路径 | 内容 |
|---|---|---|
| `api-history` | `scaffold/api/history/` | API schema 发布历史 |

> 账号(`scaffold/accounts.yaml`)、AI 配置(`scaffold/ai.yaml`)也可用 git 同步、沿用同样的行级 last-write-wins 合并(§3)。这两个文件含账号和明文密钥信息，只适合在受控的宿主业务仓同步；公开仓不要提交。

选 git 作通道:不引新依赖、复用现有 ssh 鉴权、历史天然可审计。

## 2. 同步脚本由下游维护

脚本逻辑很薄,宿主项目自己写一个就够,核心就三步:

1. `git add` 上述子集路径 → 每个有改动的子集单独 commit(如 `chore(scaffold): sync api-debug`),`git log` 可读、冲突面最小。
2. cron 前先 `git pull --rebase`;rebase 撞冲突时调 §3 的合并命令,再 `rebase --continue`。
3. `git push`(cron 用户要能 push:SSH key / deploy key / `credential.helper store`)。

cron 频率 5~15 分钟一次较合适(太频增噪音,太稀积冲突)。

## 3. 冲突自动合并:`moo:scaffold:merge-yaml`

行级合并需要真正的 YAML AST,sh+awk 等于重造 parser;本命令用 `symfony/yaml`(本就在依赖里)做 semantic merge,下游脚本在 rebase 冲突时调它:

```bash
php artisan moo:scaffold:merge-yaml scaffold/accounts.yaml            # 合并并写回
php artisan moo:scaffold:merge-yaml scaffold/accounts.yaml --dry-run  # 只输出结果到 stdout,不写回
```

合并语义:

- **accounts.yaml**:按 username **取并集** + 行级 last-write-wins(双方都有的取 `updated_at` 较新);**无删除墓碑** —— 删掉的账号若另一端还在,合并后会复活(真删干净:跟对方同步到最新后再删一次、push;误删找回走 `git log -- scaffold/accounts.yaml`,详 [`09-accounts.md`](09-accounts.md))。meta 重算(`updated_by=sync:auto-merge`)。
- **其它 yaml**:整文件按 `meta.updated_at` 比较,取较新一边覆盖。
- **元数据缺失没法仲裁**:不写回,需人工解决。
