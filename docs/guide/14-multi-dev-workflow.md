# 14 · 跨设备 / 多人协同工作流

> 多台设备或多人改同一套 schema。核心一句:改 schema 时 yaml、快照(`.snapshots/`)、migration **三个文件一起 commit**,任一落单都会让别人 pull 后产生假性漂移。

## 三组文件 = 一个原子单位

| 文件 | 用途 | 谁写它 |
|---|---|---|
| `scaffold/database/{Schema}.yaml` | 信息源(字段/索引/enum) | designer save / 手动改 |
| `scaffold/database/.snapshots/{Schema}.yaml` | 上次 migrate 时的 yaml 快照(diff 基线) | `moo:migration` / designer 生成 migration 后自动写 |
| `database/migrations/*.php` | DDL 落地脚本 | designer "生成 migration" / `moo:migration` |

> 三者必须一起进 git(详 `src/Designer/SnapshotStore.php:13`)。一起 push,同事 pull 后直跑 `php artisan migrate` 即同步。

## 跨设备工作流

**设备 A(改动方)**

1. 改 yaml(designer save 或手编)。
2. designer → "生成 migration" 按钮(同时 `captureTables` 推进 `.snapshots/` 对应表段)。
3. `php artisan migrate`(推 DB,必跑)。
4. `git commit && git push`(三组文件一起 push)。

**设备 B / 新机器(同步方)**

1. `git pull`(拿到三个同步文件)。
2. `php artisan migrate`(推 B 的 DB,必跑)。
3. 打开 designer → current yaml = 基线 = DB → 无 diff。

## 多人协同场景

**改不同表(常态)** — `captureTables` 是 per-table 子树更新,改不同表天然不撞,各自 push 自己那套三件文件即可。

**改同一张表(谨慎)** — 第二个 push 会撞 git 冲突(`{Schema}.yaml` 和 `.snapshots/{Schema}.yaml` 同段被同时改)。解决:先 `git pull` → resolve yaml + 快照冲突 → designer 看 diff(可能需重生成 migration)→ commit + push。

**同事 push 了你没 pull** — designer 自动保存时 `baseline_drift` 守护会拒生成 create_table 并报红 banner。按序救:

1. 关 designer。
2. `git stash`(若有未 commit 改动)。
3. `git pull`。
4. `php artisan migrate`(跑同事的新 migration)。
5. `git stash pop`。
6. 重开 designer。

**新机器 / 新 dev 接手**

1. `git clone <repo> && composer install`。
2. `cp .env.example .env` → 配 DB → `php artisan key:generate`。
3. `php artisan migrate`(跑全部历史 migration)。
4. `php artisan moo:fresh`(重建 storage 缓存,Request 文件等都靠它)。

> `.snapshots/` 已随 pull 下来,不用跑 `snapshot:init`;打开 designer 应全 schema "未改动"。

## 常见漂移 · 症状 → 原因 → 解法

| 症状 | 原因 → 解法 |
|---|---|
| 同事 pull 后报基线漂移 | 你 push 了 yaml 却没本地 `migrate`,DB 没推进 → 你自己 migrate,同事 pull 后也 migrate |
| 同事 pull 后报"无基线,全 create" | `git add` 漏了 `.snapshots/` → `git status` 看未 staged,补进 commit |
| 后 push 的撞 yaml 冲突 | 没 pull 就改 → 跨设备永远先 `git pull`,以 origin 为信息源 |
| 手写 migration 后时序错乱 | 绕过 designer 手 add → 别绕;真要手写,同步 yaml 后跑 `php artisan moo:snapshot:init --schema=X --force` 重锚基线 |
| `.snapshots/` 跟 yaml 冲突 | **别 `rm` 快照**(会触发漂移守护反拒生成)→ 手动 resolve,保留最新一次 migrate 时的 yaml 状态 |

## 日常 commit 模板

```bash
git add scaffold/database/{Schema}.yaml \
        scaffold/database/.snapshots/{Schema}.yaml \
        database/migrations/{timestamp}_*.php

git commit -m "feat({Schema}): 加 X 字段 / 改 Y 索引"

# commit 前过一遍:
# [ ] yaml 是 designer save 完整推下来的
# [ ] .snapshots/{Schema}.yaml 被 captureTables 推进了(git status 看)
# [ ] migration 已生成且本地 migrate 跑过
```

## 紧急 · 基线弄丢了 / 弄脏了

```bash
# .snapshots/X.yaml 误删
git checkout HEAD -- scaffold/database/.snapshots/X.yaml

# 想丢自己改动重来
git restore scaffold/database/X.yaml scaffold/database/.snapshots/X.yaml

# 全 schema 基线丢了(新机器误删):以当前 yaml 锚基线
php artisan moo:snapshot:init

# 某 schema 基线跟 yaml 严重漂移,强制重锚
php artisan moo:snapshot:init --schema=X --force
```

> DB / 基线 / yaml 三方不一致时,通常 DB 是事实:yaml + 基线错了就 `git pull` 以同事为准;DB 错了(忘 migrate)就 `php artisan migrate`。

## 运行时状态同步与 source code 的分界

多端 git 同步的是运行时积累的状态(accounts / api-debug / api-history),**不涉及** `scaffold/database/` —— 那是 source code,走本篇的正常 git 工作流。同步脚本由下游各自维护(本包只提供 `moo:scaffold:merge-yaml` 合并命令);runtime 错误 / 慢 SQL 走云端(见 [16-cloud-push.md](16-cloud-push.md)),不走 git。详 [11-sync.md](11-sync.md)。
