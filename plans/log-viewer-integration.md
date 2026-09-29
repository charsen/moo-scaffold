---
title: Scaffold 应用日志接入
group: Scaffold 研发计划
order: 20
---
# Scaffold 应用日志接入

## 已确认范围

- Scaffold 顶栏增加「应用日志」，使用 `opcodesio/log-viewer` 的原生查看、搜索和堆栈展示。
- 页面为 `/<scaffold-prefix>/logs`，API 为 `/<scaffold-prefix>/logs/api/*`。
- 页面、API 和签名下载均复用 Scaffold 开发账号及登录 cookie；所有环境强制登录。
- 匿名页面跳 Scaffold 登录；匿名 API 返回 401。账号停用、删除或 cookie 过期后拒绝访问。
- 原 `/mooeen-log-viewer` 页面和 API 返回 404，不保留重定向或第二套入口。
- 首版禁止删除日志文件、目录和批量删除；生产或强制只读时拒绝显式写请求。
- S-Cloud 继续负责运行时异常、慢 SQL 的汇聚和处置；应用日志读取当前 Host 的日志文件。

## 接入边界

Scaffold 直接依赖 Log Viewer `^3.24`，并在其 Provider 注册路由前统一配置地址和中间件。Host 的日志文件范围、排除规则、时区等配置保持由 `config/log-viewer.php` 定义；路由和账号接入集中在 Scaffold。

日志入口沿用 `scaffold.route.middleware` 的 cookie 处理边界，补齐既有受保护路由的 session、queued cookie 和 CSRF 链。当前默认外层为 `web`；Host 显式使用空中间件组时，不能额外引入一次 `EncryptCookies` 解密。

Log Viewer 自定义 layout 只适配 CSP nonce 和「返回 Scaffold」，保留上游界面及 API 协议。应用日志 API 是 Scaffold JSON 信封的第三方协议例外。

关闭 Log Viewer、Scaffold 路由或 Scaffold 认证时，日志入口不对外开放，导航也隐藏。原有 Scaffold 开放模式不因此改为强制登录。

## 暂不做

- 业务人员账号、JWT 或独立日志账号接入。
- 日志解析器、查询界面或跨项目日志中心的重写。
- 新增远程 Host 配置、日志删除能力或浏览器全流程回归。
- Git 提交、合并、发布及服务器部署。

## 验证与进度

- [x] 包内真实 Log Viewer 路由定向测试：匿名、登录、失效账号、旧地址、可变前缀和关闭开关。
- [x] 删除拒绝、生产只读、CSRF、签名下载和 CSP nonce 的必要回归。
- [x] Provider 注册顺序、编译路由及 Scaffold 原有入口的必要回归。
- [x] 真实 path-repository Host 的路由反射和定向 HTTP 集成验证，使用隔离账号及日志夹具。
- [x] 完整 diff、相关 PHP 风格、Composer manifest 和文档链接检查。

2026-09-29 验收结果：

- 新增日志集成测试 10 项通过（102 assertions）；既有认证、只读和首页/数据库文档回归 17 项通过；JSON 信封回归 6 项通过。
- 真实 Host 的隔离 HttpKernel 验收 16 项通过：包含原生文件与日志读取、CSP、签名下载再次鉴权、旧地址 404、停用账号、生产写拒绝、本地 CSRF 和编译路由。
- 测试账号仅覆盖 AccountStore 的夹具路径，其余逻辑沿用生产实现；Host 使用内存数据库、array session/cache、临时日志与 Blade 缓存。夹具已清理，现有账号与日志未修改。
- 相关 PHP 定向 Pint、Composer 严格 manifest 校验、diff 空白和新增文档链接检查通过。
- 本次运行组合为 Log Viewer 3.24.2 / Laravel 12。依赖下限收紧至 3.24，避免 3.21 缺少布局所需的原生资源接口。

未运行 E2E、全量测试或 Admin 双 smoke；未在 Laravel 10/11 上执行测试，未生成 Host 的磁盘配置/路由缓存，编译路由仅在隔离进程内验证。未执行 Git 提交、合并、发布或部署。
