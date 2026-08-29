# CLAUDE.md

本仓库的完整协作、schema/codegen、研发后台、安全和验证规则统一维护在 [`AGENTS.md`](./AGENTS.md)。开始任务前必须完整阅读并遵守它，本文件不维护第二份重复规则。

特别提醒：

- 开工先读 `NOTES.md`，再按任务读 `docs/overview.md`、`docs/guide/` 和 `docs/yaml-style.md`。
- 本仓是 Composer library，没有独立 `artisan`；真实生成必须在 path repository 接入的 host 中执行。
- YAML、snapshot、migration 是原子变更，stubs 是下游生成规范，`--force` 不能扩大覆盖边界。
- Scaffold 负责研发后台与编排，运行时采集/Cloud 同步由 `moo-monitor-laravel` 负责。
