# 05 · API 文档与调试

> `moo:api` 把真实路由扫成接口 YAML,`/scaffold/api/*` 负责浏览 + 类 Postman 调试。文档是开发期"边写边试"用的,不是对外 API 门户。
>
> `moo:api` 参数查 [03-cli-reference.md](03-cli-reference.md#moo-api)。

## 入口

| 路径 | 干什么 |
|---|---|
| `/scaffold/api` | 接口文档,左侧按 app/目录/controller 分层 |
| `/scaffold/api/show` | 单接口详情页 |
| `/scaffold/api/request` | 调试器:选 host + 填参数 + 发请求(多接口 tabs) |
| `/scaffold/api/proxy` | 后端代理转发,绕过浏览器跨域 |

## 1. 生成接口 YAML

```bash
php artisan moo:api admin -a          # admin 下全部 namespace
php artisan moo:api admin Light       # admin / Light 单 namespace
```

输出到 `scaffold/api/{app}/{namespace}/{Controller}.yaml`(如 `scaffold/api/api/Light/Book.yaml`)。action key 格式 `{action}_{http_method}`(如 `index_get` / `update_put`),避免同名 action 多 method 冲突。

`--stale=` 控制路由删了的接口:

| 值 | 行为 |
|---|---|
| `deprecate`(默认) | 标"已弃用",留在 YAML |
| `keep` | YAML 不动 |
| `delete` | 从 YAML 删掉 |

> 扫描前提:controller 里**真实存在 public function** 且**有路由指向**,YAML 里的孤儿不算。

## 2. 配 host 切换

`config/scaffold.php` 的 `hosts` 块,调试器顶部下拉选环境,请求拼这个 host:

```php
'hosts' => [
    '开发环境' => 'http://localhost',
    '正式环境' => 'https://api.example.com',
],
```

> host 只允许 `hosts` 白名单内的值(SSRF 防护),代理不会发去任意地址。

## 3. 打开调试器发请求

进 `/scaffold/api/request`,左 sidebar 点接口开 tab,填参数,发请求。参数由四处综合而来,不只读 YAML:

1. **Controller 方法签名** — reflection 读
2. **`FormRequest::rules()`** — 参数名建议命名 `$request`,调试器才自动识别
3. **YAML `prototype` / `url_params` / `body_params`** — 显式参数定义

URL 里的 `{id}` 占位符会**自动替换为对应模型最后一条记录主键**:先反射 controller 拿绑定 model,取不到再看 `FormRequest` 的 `exists:Model,id` 规则,定位后取 `latest()` 一条主键;都落空则保持原样。

## 4. Authorization 头(需鉴权接口)

YAML `authorization: true` 的接口,调试器给一个**手动填写**的 `Authorization: Bearer <token>` 输入框,token 自己粘。`config/scaffold.php` 的 `authorization.exclude_actions` 列了哪些 action 免这个头(如 login)。

## 多接口 tabs

`/scaffold/api/request` 顶部 top-bar:左 260px 是 app 选择器,右占满是 tabs 区,可同时挂多接口对比 response。

- 点接口开新 tab,**同 controller+action 不重开**;**上限 10 个**,超出 toast 提示
- 切 tab:params 走 DOM swap(保留编辑状态),response 走 state-based(内存缓存)
- 切 app = 新页面 = 新 tabs(跨 app 不保留)

## 历史抽屉

- **本地历史抽屉**(api/request 页内右侧)— `localStorage` 存最多 **100** 条,纯本机不进 git,分页每页 10 条。**点行回填** method / host / uri / headers / params 免重输,敏感 header 显示时 mask。

## 发布历史

`moo:api` 每次有**结构变化**时在 `scaffold/api/history/{timestamp}.yaml` 落快照,记接口演变。每条 action 带 `operation` 状态,首页 badge 色标:

| 状态 | 触发 |
|---|---|
| 新增 | controller YAML 本次首次发布 |
| 追加 | 已有 controller 新加 action |
| 删除 | `--stale=delete` 移除的 action |
| 弃用 | `--stale=deprecate` 标 `deprecated:true` 的 action |

> 只刷已有 action metadata、无真改动 = 不落历史文件。首页 `/scaffold` 卡片展示最近几次,点接口跳调试页。

## 代理(`/scaffold/api/proxy`)

浏览器跨域拦不住时,请求 POST 给后端代发。配置:

```php
'proxy' => [
    'timeout' => (int) env('SCAFFOLD_PROXY_TIMEOUT', 30),
],
```

故意不留兜底开关:**TLS 永远校验**(证书错直接报 `cURL error 60`,去修证书);**不 follow redirect**(301/302 原样返回,说明 `hosts` 的 scheme 写错,改 config)。

## 常见踩坑

- **没扫到接口** — controller 无真实 public function 或无路由指向
- **参数显示不全** — 方法签名没用 `$request` 命名 / 没装对应 FormRequest
- **`{id}` 没替换** — 对应表没数据,或 controller 没绑 model、FormRequest 也无 `exists:Model,id`
- **Authorization 一直空** — YAML `authorization: false`,或 action 在 `exclude_actions`
- **响应解析出错** — 后端返回非 JSON,调试器原样显示 raw,看 Network 面板
- **历史抽屉只在本机** — 抽屉是 localStorage 个人级,纯本机、不进 git、不跨成员共享
- **tab 上限 10 / 切 app tabs 不见** — 均为设计意图,关旧开新
