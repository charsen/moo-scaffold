---
title: 20 · 应用日志
group: Scaffold 操作手册
order: 200
---
# 20 · 应用日志

登录 Scaffold 后，从顶栏「应用日志」进入 `/scaffold/logs`，按文件、等级、时间和关键词查看日志及异常堆栈。查看器由 [opcodesio/log-viewer](https://log-viewer.opcodes.io/docs/3.x/) 提供，页面内可返回 Scaffold。

## 登录与地址

应用日志使用 `scaffold/accounts.yaml` 中的开发账号和现有 `scaffold_auth` cookie。开发、测试和生产环境都必须登录；匿名访问页面跳到 Scaffold 登录，访问日志 API 返回 401。停用、删除账号或登录过期后，已有 cookie 也无法继续访问日志。

| 入口 | 地址 |
|---|---|
| 日志页面 | `/scaffold/logs` |
| Log Viewer 原生 API | `/scaffold/logs/api/*` |
| Scaffold 登录 | `/scaffold/login` |

以上地址跟随 `scaffold.route.prefix`。旧 `/mooeen-log-viewer`、默认 `/log-viewer` 及它们的 API 不再注册，返回 404。

## 日志配置

Log Viewer 由 Scaffold 的 Composer 依赖带入，要求 `^3.24`，统一配置页面地址、认证中间件和返回入口。Host 可继续在 `config/log-viewer.php` 中配置 `include_files`、`exclude_files`、时区、排序等日志查看选项，无需另写登录 Controller 或复制包的查询逻辑。

`LOG_VIEWER_ENABLED=false` 可关闭查看器。Scaffold 路由或 Scaffold 认证关闭时，日志入口也不会开放，导航隐藏。

Host 既有的 `log-viewer.route_path`、`middleware`、`api_middleware` 和返回地址由 Scaffold 接管。升级后，旧地址不会作为兼容入口继续服务。部署若缓存了路由或配置，应通过 Host 的既有部署流程重建缓存。

## 操作边界

- 所有已登录且启用的 Scaffold 开发账号均可查看日志；签名下载也要求同一登录态。
- 日志文件、目录和批量删除在所有环境禁用，后端同步拒绝。
- 清理查看器缓存等显式写操作只在可写环境允许，并受 CSRF 校验；生产或 `SCAFFOLD_CONFIG_READONLY=true` 时拒绝。
- 日志读取过程中产生的查看器解析缓存由上游管理。
- 日志页面沿用 Scaffold 的 CSP，通过自定义 layout 为内联资源添加 nonce。

日志 API 保留 Log Viewer 原生协议，不使用 Scaffold 的 `{ok,data}` 信封；详见 [Web JSON 契约](19-web-json-contract.md)。

## 与 S-Cloud 的关系

「应用日志」查看当前 Host 可访问的日志文件；S-Cloud 汇聚运行时异常和慢 SQL，并提供云端处置。两者入口并存，采集和推云链路仍由 `moo-monitor-laravel` 提供，参见 [云端汇聚](16-cloud-push.md)。
