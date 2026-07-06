# 操作手册

按业务模块组织。"用过但忘了具体怎么用"时来这里找,一页目录速查,不必再回去翻主 README。

## 模块手册

| # | 模块 | Web 入口 / CLI | 文档 |
|---|---|---|---|
| 01 | 安装与初始化 | `composer require` + `moo:init` | [01-install.md](01-install.md) |
| 02 | Schema 与代码生成 | `moo:fresh` / `moo:free` | [02-schema-codegen.md](02-schema-codegen.md) |
| 03 | 命令速查 | 所有 `moo:*` | [03-cli-reference.md](03-cli-reference.md) |
| 04 | 数据库设计器 + 字典 | `/scaffold/db/designer` `/scaffold/dictionaries` | [04-db-docs-designer.md](04-db-docs-designer.md) |
| 05 | API 文档与调试 | `/scaffold/api` `/scaffold/api/request` | [05-api-debugger.md](05-api-debugger.md) |
| 06 | ACL 生成与查看 | `moo:auth` / `/scaffold/routes` | [06-acl.md](06-acl.md) |
| 09 | 账号管理 | `/scaffold/accounts` + `moo:account:add` | [09-accounts.md](09-accounts.md) |
| 10 | 配置 UI | `/scaffold/config` `/scaffold/config/env` | [10-config-ui.md](10-config-ui.md) |
| 11 | 多端 git 同步 | `moo:scaffold:merge-yaml`(编排脚本下游维护) | [11-sync.md](11-sync.md) |
| 12 | 安全模型 | dev-only / prod-readonly / CSP / CSRF | [12-security.md](12-security.md) |
| 13 | 排错合集 | — | [13-troubleshooting.md](13-troubleshooting.md) |
| 14 | 跨设备 / 多人协同 | `.snapshots/` + yaml + migration 三件套 | [14-multi-dev-workflow.md](14-multi-dev-workflow.md) |
| 16 | 云端汇聚(运行时错误 / 慢 SQL / Todos)+ AI 接入 | `moo:cloud:push` / `moo:cloud:mcp` / `moo:monitor:migrate` | [16-cloud-push.md](16-cloud-push.md) |
| 17 | 开发文档中心(MD + 深链 shortcode + Mermaid 流程图) | `/scaffold/docs` | [17-docs-center.md](17-docs-center.md) |
| 18 | 扩展包 schema 管理与代码生成(出身模型) | 设计器分块 + `moo:free admin {包schema}` | [18-package-schema.md](18-package-schema.md) |

## 其它参考

- [`../schema_demo.yaml`](../schema_demo.yaml) — schema YAML 完整样例
- [`../yaml-style.md`](../yaml-style.md) — yaml 风格规范 + `unique` 双语义(必读)
