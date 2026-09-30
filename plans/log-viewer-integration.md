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

Log Viewer 自定义 layout 适配 CSP nonce、「返回 Scaffold」、登录失效提示和只读菜单展示，保留上游界面及 API 协议。应用日志 API 是 Scaffold JSON 信封的第三方协议例外。

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

## 2026-09-30 已批准体验优化

- [x] 日志 API 的 401 + 专用认证响应头触发重新登录遮罩，回跳使用当前页面地址（包含筛选与锚点），不使用 API 地址。遮罩显示时隔离日志区键盘焦点。
- [x] 生产或强制只读显示状态标识，隐藏文件、目录、全部索引清理菜单；后端写守卫保持原有规则。
- [x] 本布局内联安装资源，覆盖上游依据已发布资源计算的过期标志，避免遗留 manifest 误报。

菜单适配集中在页面桥接脚本，按 Log Viewer 3.24 的三个维护标签精确匹配动态菜单；不修改上游 bundle 或使用 Vue 内部实例。定向测试核对安装版本的组件标签，依赖升级时须复核这个适配点。

本轮定向验证：日志集成 12 项（126 assertions）、JavaScript VM 行为 7 项、真实 path-repository Host 页面渲染 8 条检查通过；PHP 风格、JS 语法与 diff 检查通过。系统盘临时空间不足时，将本轮夹具迁到开发盘后完成验证，未清理其他文件。

本轮未运行 E2E、全量测试或 Admin 双 smoke；未执行 Git 提交、合并、发布或部署。日志文件范围、账号角色策略与主题统一不在本轮范围。


## 2026-09-30 代码复盘与性能优化

- [x] 日志 API、页面和下载认证复用现有 Scaffold 中间件；上游回调只消费本次请求的认证属性，单次 API 请求认证从三次减为一次，下一请求重新校验账号启用状态。
- [x] 只读菜单首次扫描后，仅收集新增子树及变更菜单，使用 Set 和微任务合并重复更新；保留 style 与文字变化响应，不重扫日志内容。
- [x] 上游 Vue 标签契约检查移入 Composer 依赖已安装的 PHP 集成测试，JavaScript 行为测试在无 vendor 的干净目录可独立运行。
- [x] 自有样式移入独立 Sass 并生成 log-viewer.css；静态守卫只放行日志布局中准确匹配的第三方 nonce 注入表达式，其余内联样式仍拒绝。
- [x] 修正旧 Provider 文档并补当前公开文档守卫。
- [x] 按用户确认保留 422/522 交互规则：有表单及实际字段控件的错误为 422；无表单动作统一 522 异常提醒，包括 ids 必填/格式失败。生成器与 Request 模板保持原样，补真实生成 Request 的 522、正常输入、重复生成保护和 force 回归；修正文档中的冲突补充。

定向 PHP 回归 37 项 / 267 assertions 通过；无 vendor 的 JS 行为 7 项通过；真实 path-repository Host 隔离 HTTP 8 条检查通过（登录、API、CSP、桥接资源引用、只读与停用账号）。首次 Host 引导未绑定 request 导致夹具启动失败，补齐引导后上述 8 条实际执行通过，不计失败启动为验收。临时账号、日志、内存库和编译缓存均隔离并清理。

CSS 构建、PHP dirty Pint、JS 语法、UI 静态守卫、资源存在性和 CSS 体积预算通过。静态守卫另以临时布局验证：准确的上游 CSS nonce 表达式允许，新增普通内联 style 拒绝。未运行 E2E、全量测试或 Admin 双 smoke，未提交、合并、发版或部署。Host 升级时须重新发布 Scaffold public 资源；Laravel 10/11 和浏览器视觉尚未验证。

跨仓旧 Provider 短名审计返回 1（CODE 1 / DOC 36）：唯一 CODE 是任务外仓自持旧版包中的类定义，未作为本包消费方迁移，也未修改该仓；其余文档命中未扩大处理范围。本仓 README/docs 的旧 Provider 守卫通过，跨仓审计不宣称全绿。

## 2026-09-30 二次复盘修复

- [x] 静态守卫的通过/失败计数改为普通算术赋值，避免 `set -e` 在新版 Bash 下因首次后置自增返回非零而提前退出；新增正常与违规夹具，要求完整汇总全部检查。
- [x] `ScaffoldAuth::authenticateRequest()` 在原有 username/last_active 之外仅增加管理角色布尔值，中间件复用该判定，避免第二次读取账号 YAML。Cookie 格式不变，宿主覆盖认证方法并返回原结构时仍沿用既有角色判断。
- [x] 核查包内、宿主、实现/绑定、测试/文档引用；当前工作区未发现宿主覆盖认证返回结构，另补旧结构兼容回归。账号角色与停用不跨请求缓存，422/522、Request 生成器和模板未改动。

定向认证与日志集成 26 项 / 176 assertions 通过；静态守卫夹具 2 项 / 4 assertions 在 Bash 3.2 和 5.2.37 均通过。临时编译的 Bash 5.2.37 实际复现旧脚本首条通过后退出 1，修复后本仓检查完整通过，违规夹具完整汇总且退出 1。

真实 path-repository Host 的隔离 HttpKernel 检查 9 条通过，覆盖每请求一次账号读取、角色降级和停用后的 401；使用临时账号、日志、缓存与内存数据库，未修改 Host 代码或既有数据。Bash 5.2 验证在 macOS 执行，未运行 Ubuntu runner、E2E 或全量测试。用户已授权本轮修复提交并分别本地合入 dev/master；不含推送、tag 或部署。
