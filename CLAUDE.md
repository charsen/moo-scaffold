# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 协同开发原则与边界

1. 先列计划，批准再动手
   开始任何任务前，先列出执行步骤并等待明确批准。计划未经确认，不得开始实施。
2. 改文件前必须先读文件
   编辑任何文件之前，必须先读一遍。
3. 别重复造轮子
   尽量缩小改动范围，优先复用项目已有的抽象和函数。绝对禁止重新穿透多层调用链实现一遍。
4. 不确定先说，不要猜
   如果没有可参考的先例，停下来问。不要自己发明需求—那是人类的工作。
5. 中途转向，先问再动
   实施可能影响用户的改动前，先确认方案。如果范围发生变化，重新制定计划。
6. 计划外的问题先报告
   遇到与当前任务无关的废弃代码或可疑行为，说出来。严禁自己动手。
7. 改了什么必须汇报
   提交之前，把完整的改动差异展示给用户，并获得明确批准。
8. 没跑过测试不算完成
   宣布实现就绪前，跑最小验证：`vendor/bin/pint --dirty`（lint，只查未提交改动）+ `composer test`（Pest，范围大可先 `composer test:filter -- <关键词>`）；涉及 `/scaffold` 后台 UI 的改动，再跑 `npm run test:e2e:safe`。

## 模型执行守则

1. 报告完成前，先用真实工具结果逐条自审每个声称，不许给乐观的假状态。
2. 关键成果用独立子代理、全新上下文复核，别只靠自我批评。
3. 用 notes.md 当长期记忆：踩过的坑、确认过的做法，一条一行；每次开工先读。

## 项目速览

Schema 驱动的 Laravel 代码生成器 + 研发后台（Composer library，Laravel 12 / PHP 8.2+）。一份 YAML 作为单一事实源，生成 Model / Resource / Controller / Request / Migration / 接口文档 / ACL / 多语言；另挂一个 `/scaffold/*` 研发后台（数据库设计器、接口调试、ACL 视图、配置中心、文档中心）。

- **两大支柱**：codegen 流水线（`src/Generator/` + `src/Command/` 的 `moo:*` 命令族）；研发后台（`src/Http/` + `src/Designer/`，Blade + Alpine.js CSP-safe + jQuery）。
- **`stubs/*.stub` 即编码规范**：模板与 `src/Foundation/` 基类定义了生成代码「应该长什么样」，改这里按「改规范」的严肃度对待。
- **自身无数据库**：不建任何表；账号等运行期数据是磁盘 YAML，schema 缓存落宿主 `storage/scaffold/`（gitignored）。设计器 / migration 操作的都是宿主项目的 DB。
- **dev-only 写、prod 只读**：CLI 由 `config('scaffold.only_in_local')` 把门，Web 写路由由 `EnforceScaffoldWritable` 中间件把门。
- **本包是 library，没有独立 artisan**：`moo:*` 命令要在接入了本包的宿主 Laravel 项目里跑。
- 深入阅读：`docs/overview.md`（是什么/为什么）→ `docs/guide/`（怎么用，含 `03-cli-reference.md` 命令速查）→ `docs/yaml-style.md`（schema 写法）。

## 常用命令

前置：本包自带独立 dev 依赖（pest / pint / testbench），与宿主 vendor 无关；若包目录下 `vendor/` 不存在，先跑一次 `composer install`。

| 用途 | 命令 |
|---|---|
| 单测（Pest + Testbench） | `composer test`；过滤：`composer test:filter -- <关键词>` |
| Lint（Pint） | `vendor/bin/pint --dirty` |
| 编译 CSS | `npm run build:css`（改 `public/sass/` 后必须重编，css 产物入仓） |
| e2e（Playwright 真机） | `npm run test:e2e:safe` —— 前置：宿主项目起服务、`.env` 按 `.env.e2e.example` 配好。`:safe` 变体结束后自动回滚宿主 scaffold 数据目录（`E2E_HOST_SCAFFOLD_DB_PATH`）；**别用裸 `test:e2e`**，会把宿主数据跑脏。 |

## 仓库约定

- 修完 commit + push master 即可（开发副本经 composer path 软链实时生效）。**bump 版本 / 写 CHANGELOG 版本条目 / 打 tag 属于发版动作，等维护者明确发话再做。**
- commit message 不加 `Co-Authored-By:` 等生成器尾注。
- 本仓开源（GitHub 公开）：代码、文档、notes.md 里不出现内部项目名、内部域名、密钥。
