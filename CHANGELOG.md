# Changelog

## Unreleased

- B-01 方案 B：新增 `Contracts\OperatorResolver` + 默认 `Support\GuardOperatorResolver`（auth()->id()，未登录 null），开出 host 操作人身份注入缝。
- `HasOperator` 上移为共享 `Mooeen\Scaffold\Concerns\HasOperator`；生成器不再复制本地 Trait/stub，无身份统一写 null。

## 2.1.2

- Docs center: the bare `/docs` URL now opens a catalog home page with drag-and-drop ordering — rows within a group, or whole groups at once. Order is written back surgically to each doc's front-matter `order` line using gapped global numbering (10/20/30…), so diffs stay one-line clean.
- Docs center: full-text search across every doc source (host + packages) from the catalog page, with hit highlighting; result links carry `?hl=` so the reading page scrolls to and marks the first match.
- Docs center: prev/next navigation at the bottom of reading pages, following the same global reading order.
- Docs center UI polish: compact single-row header (title + search + count), dark-theme readability (zebra rows, accent group bars), roomier rows, grip-only drag handles, and the redundant slug column removed. New-doc template no longer hardcodes an `order`, so new docs sink to the end of their group until dragged into place.
- Scaffold admin sidebars are drag-resizable with persisted widths: all navigation trees share one width, the designer table list keeps its own.
- Shared runtime foundations moved into the package (translation merging loader, Eloquent base filter, snowflake primary-key concern); generators stop emitting per-app copies. Adds `tucker-eric/eloquentfilter` as a direct dependency.
- Generator/write hardening: new schema files are dumped through the YAML formatter, file-write failures throw instead of passing silently, and column `width`/`minWidth` values pass through as-is (no `px` suffix appended — the front end normalizes).
- Laravel 10, 11, and 12 are all supported (`laravel/framework ^10 || ^11 || ^12`), with a 2.x branch alias for path-repository development.
- UI copy punctuation normalized to full-width in Chinese contexts; CI adds a quality workflow (composer validate, Pint, tests, dependency audit).

## 2.1.1

- Extension-package controllers now generate and reference an in-package `HandlesResourceActions` trait, removing the package-to-host base-trait dependency.
- Request traits can delegate to package-owned tables (`scaffold.package_request_traits`).
- Internal consistency refactor with no behavior change: unified tri-state file-write reporting, added an `isForced()` command helper, extracted the API debugger's HTTP proxy into its own controller, and decoupled the utility layer from the schema loader.
- Expanded test coverage and hardened a configuration test against persistent test base paths.

## 2.1.0

- Initial public release based on the 2.x line.
- Includes the current schema-driven code generator, scaffold admin UI, database designer, API debugger, ACL tooling, configuration UI, docs center, and moo-monitor-laravel integration.
- Removes non-public workflow notes and handoff material from the public package.
