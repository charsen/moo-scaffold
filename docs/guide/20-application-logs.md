---
title: 20 · 应用日志
group: Scaffold 操作手册
order: 200
---
# 20 · 应用日志

登录 Scaffold 后，从顶栏「应用日志」进入 `/scaffold/logs`，按文件、等级、时间和关键词查看日志及异常堆栈。查看器由 [opcodesio/log-viewer](https://log-viewer.opcodes.io/docs/3.x/) 提供，页面内可返回 Scaffold。

## 登录与地址

应用日志使用 `scaffold/accounts.yaml` 中的开发账号和现有 `scaffold_auth` cookie。开发、测试和生产环境都必须登录；匿名访问页面跳到 Scaffold 登录，访问日志 API 返回 401。停用、删除账号或登录过期后，已有 cookie 也无法继续访问日志。

查看期间登录失效时，页面提示「重新登录」。点击后使用 Scaffold 登录页，登录成功返回当前日志页面，保留地址中的文件、筛选参数和锚点。

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

页面直接内联当前安装版本的查看器资源，无需发布 Log Viewer 静态资源；遗留的已发布资源不会触发当前页面的资源过期提示。

Scaffold 自有的登录失效遮罩与只读标识样式位于 `public/css/log-viewer.css`，安装或升级后需按 Scaffold 安装流程重新发布 `public` 资源：

```bash
php artisan vendor:publish --provider="Mooeen\Scaffold\MooeenScaffoldServiceProvider" --tag=public --force
```

日志请求在 Scaffold 中间件中完成一次认证，上游查看器复用当前请求的认证结果；不跨请求缓存账号状态，停用账号在下一次请求即被拒绝。

## 操作边界

- 所有已登录且启用的 Scaffold 开发账号均可查看日志；签名下载也要求同一登录态。
- 日志文件、目录和批量删除在所有环境禁用，后端同步拒绝。
- 清理查看器缓存等显式写操作只在可写环境允许，并受 CSRF 校验；生产或 `SCAFFOLD_CONFIG_READONLY=true` 时显示「只读模式」、隐藏文件/目录/全部索引清理菜单，后端同步拒绝。查看、搜索、刷新和下载仍可用。
- 日志读取过程中产生的查看器解析缓存由上游管理。
- 日志页面沿用 Scaffold 的 CSP，通过自定义 layout 为内联资源添加 nonce。

日志 API 保留 Log Viewer 原生协议，不使用 Scaffold 的 `{ok,data}` 信封；详见 [Web JSON 契约](19-web-json-contract.md)。

## 与 S-Cloud 的关系

「应用日志」查看当前 Host 可访问的日志文件；S-Cloud 汇聚运行时异常和慢 SQL，并提供云端处置。两者入口并存，采集和推云链路仍由 `moo-monitor-laravel` 提供，参见 [云端汇聚](16-cloud-push.md)。
