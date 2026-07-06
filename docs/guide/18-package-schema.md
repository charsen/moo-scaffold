# 18 · 扩展包 schema 管理与代码生成(出身模型)

> 在 host 项目里,用 scaffold 直接管理**软链安装的扩展包**(如 moo-system / moo-radar)的 schema 与 docs:设计器可视化编辑、`moo:*` 生成的代码全部落到**包自己的仓库目录**,host 一个字节不动。

## 前置条件(约定即配置,无需任何 config)

扩展包被自动发现并纳管,只要同时满足:

1. **软链安装**(composer path repository)——这是「可写」的硬线:软链 = 写 vendor 即写真仓 = 可写;从 VCS 拉的 vendor 拷贝 = 只读(设计器锁保存、CLI 生成直接拒),**没有开关可绕**。
2. 包根有 `scaffold/database/` 目录(存放该包的 schema yaml,这就是发现标记)。
3. `composer.json` 有 psr-4 autoload(第一个命名空间根即生成代码的命名空间)。
4. 包的 `routes/admin.php` 里有 `// :insert_code_here:do_not_delete` 插入标记(一次性手动加)。

## 出身(origin)决定一切

schema 的**出身**在它躺在哪个 `scaffold/database/` 里时就定了:host 目录的是 host schema,包目录的是包 schema。没有「目标切换器」,不需要也不会问你「生成到哪」——选了 schema,落点即定。

- **Web 端**:设计器 / 数据库文档 / 数据字典 / 开发文档中心的列表按出身**分块呈现**(包块带 📦 标识);包 schema / 包文档详情页带 `📦 包名` 徽标提醒「改动落包仓,commit 到该仓」;只读包(vendor 拷贝)整页写按钮灰化。开发文档中心的包文档要求包根有 `docs/` 目录(同 `scaffold/database/` 一样是发现标记)。
- **CLI 端**:选 schema 的交互列表里包项标注 `System〔moo-system 扩展包〕`;包 schema 只支持 `admin` 端——选了 `api` 等其它 app 时列表不出现包 schema,显式传参 `moo:free api System` 会 fail-fast 报错。

## 落点约定:源资产随包,聚合随 host

| 产物 | 落点 |
|---|---|
| schema yaml / `.snapshots/` / migration | 包 `scaffold/database/`、包 `database/migrations/`(日期命名) |
| Model / Filter / Trait / Enum | 包 `src/Models/`(平铺,无模块子目录) |
| Controller / Controller Trait | 包 `src/Http/Controllers/Admin/`(平铺) |
| Request | 包 `src/Http/Requests/{Controller}/` |
| Resource | 包 `src/Http/Resources/` |
| 路由 | 包 `routes/admin.php` 标记处 |
| 包字段 i18n 词条 | 包 `lang/{locale}/`(词条子集;包内**手写词条保留不删**,词条值以 schema 为真源) |
| ACL / api 文档 / host 全量 i18n / Seeder(`-F`)/ Factory / 测试 | **host**(聚合物与运行时归属 host;`moo:auth` / `moo:api` 经 `controller.admin.extra_modules` 已包感知) |

不生成的:包 schema 跳过 TS Model(前端结合未设计);`moo:view` 不支持包。

生成的包代码引用 host 的 `App\Admin\Controllers\Traits\BaseActionTrait` 与 `Route::iResource` 宏——扩展包本就要求 host 提供这两者(与 extra_modules 同一约定)。

## 日常工作流

```bash
# 1. 设计:/scaffold/db/designer 直接编辑包 schema(或手改包里的 yaml)
# 2. 刷缓存 + 生成(交互里选带〔扩展包〕标注的 schema 即可)
php artisan moo:free admin System -a
# 3. 验收落点:看包仓 diff,而不是 host 的
git -C /path/to/moo-system diff
# 4. 满意后在包仓 commit(scaffold 不代提交)
```

`moo:adder` 同样支持:目录选择命中 extra_modules 声明且本机发现了对应软链包时,增量 action / 路由落包仓;声明了但本机是 vcs 拷贝则明确拒绝。

## 排错

- **包没出现在列表里** → 逐条核对上面 4 个前置条件;`scaffold/database/` 目录不存在是最常见原因。
- **设计器显示只读横幅「扩展包只读」** → 该包不是软链安装。改 host 的 composer path repository 配置后 `composer update 包名`。
- **路由生成失败提示缺标记** → 包 `routes/admin.php` 补 `// :insert_code_here:do_not_delete`。
- **radar 的 `routes/radar.php`** 是公开 webhook 手工路由,工具不认不碰,不要合并进 admin.php。
