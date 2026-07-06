# 06 · ACL 生成与查看

> `moo:auth` 把 controller 注释里的 `@acl` 声明汇总成配置表 + Web 查看器,代码一改权限文档跟着更新。`/scaffold/routes` 看(旧 `/scaffold/acl` 会 302 跳过来)。
>
> ACL 来源是**真实路由 + controller 里真实存在的 public method**,不是按 resource 全量猜测。

## 1. 注释里标 `@acl`

action docblock 加 `@acl` 标签,后跟 YAML 行内 label(中文 / 英文 / 可选描述):

```php
/**
 * 部门列表
 * @acl {zh-CN: 查看部门列表, en: Department List, desc: }
 */
public function index() { ... }
```

- **有 `@acl`** → 进 `config/actions.php` 主表,需授权才能访问
- **无 `@acl`** = 白名单(任何登录用户可访问),不进 `actions` 表

> ACL key 由 **controller 类 + action 推导**(走 `formatAclName`),不是标签里的文字;标签只提供 label 与白名单开关。

## 2. 生成

```bash
php artisan moo:auth admin       # 处理 admin app
php artisan moo:auth admin -r    # 同时打印路由调试信息
```

每个 app 单独跑。`moo:free admin Light -a` 流水线第 6 步已自动 = 跑一次 `moo:auth admin`;只有**只改 ACL 注释、没改 schema** 时单独跑更快。

## 3. 产物

| 文件 | 内容 |
|---|---|
| `config/actions.php` | ACL 主表:每个 app 下 `whitelist`(白名单 key 扁平数组)+ `actions`(模块 → controller → key 多层嵌套) |
| `lang/{locale}/actions.php` | 同一套 key,值换成对应语言文案;`config/scaffold.php` 的 `languages` 决定生成哪些语言(默认 `['en', 'zh-CN']`) |
| `scaffold/acl/{app}.yaml` | 给 `/scaffold/routes` 用的分层数据,每条 action 带 method / uri / acl key / label |

`config/actions.php` 结构:

```php
return [
    'admin' => [
        'whitelist' => ['xxxx', ...],          // 无标签 action 的 key
        'actions' => [
            'module-xxxx' => [                 // 模块键(带 module- 前缀)
                'controller-yyyy' => [         // controller 键(带 controller- 前缀)
                    'aaaa', 'bbbb',            // action key 列表
                ],
            ],
        ],
    ],
];
```

> `authorization.md5=true`(默认)时模块 / controller / action key 全是 md5 别名。所以**不能**用 `config('actions.admin.system.department.list')` 这种字面路径去读 —— 业务层权限校验走 Authorizable trait / policy,别直接读 config 字面 key。

## 4. 看:`/scaffold/routes`

- **顶部 app 切换** — 按 `config('scaffold.controller')` 列出
- **左 sticky sidebar** — 模块 / controller 锚点
- **右主区** — 按模块 / controller 分章节展开所有 action,显示 method / uri / ACL key / 中文标签;无 ACL 标签的标"白名单"

## 配置:`md5` 别名

```php
'authorization' => [
    'check' => false,         // 业务代码是否真的校验(scaffold 不管校验,给业务用的开关)
    'md5'   => true,          // ACL key 是否用 md5 别名
    'exclude_actions' => [    // 生成 API YAML 时不带 Authorization header 的接口
        'App\Admin\Controllers\AuthController@login',
    ],
],
```

## 改文案

文案来源是 docblock 的 `@acl` 标签。`lang/{locale}/actions.php` 每次 `moo:auth` **全量重写**,手改下次必被覆盖 —— 改注释再重跑是唯一稳的路,没有"自定义覆盖层"文件。

## 常见踩坑

- **`moo:auth` 没扫到 action** — 必须是 controller 里**真实 public function** 且**路由真实指向**,YAML 孤儿不算
- **`@acl` 写法不对** — 必须 `{zh-CN: ..., en: ..., desc: ...}` 行内格式,不是裸字符串;照现有 controller 抄
- **`config/actions.php` 改了被覆盖** — 每次 `moo:auth` 重写,**不要手改**,改注释 + 重跑
- **读 config 拿权限名却拿到 null** — 键带前缀且可能被 md5,走业务层 trait / policy
- **生产 `moo:auth` 报 "only_in_local"** — 设计意图,生成器只能本地跑(见 [12-security.md](12-security.md))
