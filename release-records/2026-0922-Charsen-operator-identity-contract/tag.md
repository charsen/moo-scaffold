# 操作者身份契约收敛（moo-scaffold 2.2.8 候选）

状态：**准备发版（未打 tag）** —— 已在 `dev` 与 `master`，工作区干净。

## 发布形态

- 本仓：**patch** tag **`2.2.8`**（作者决定不跳版本号；契约新增属向后不兼容的接口扩展，
  但按仓库惯例仍走 patch）。
- 下游：自行实现 `OperatorResolver` 的宿主/包须补 `isPlatformRoot()`；使用 scaffold 默认实现
  （`GuardOperatorResolver`，已实现并返回 false）者不受影响。
- 已改：`某个内部 Host` 的 `GetUserIdOperatorResolver`（宿主唯一绑定，同时实现 `id()` 与 `isPlatformRoot()`）。

## 本批范围

| 主题 | 内容 |
| --- | --- |
| root 身份入契约 | `OperatorResolver::isPlatformRoot()`；`GuardOperatorResolver` 默认 false |
| 授权分支修复 | `checkAuthorization()` 数组分支改逐 key `Gate::check`（原 `Gate::any` 参数颠倒 → 恒 403） |
| Resource 隐藏语义 | `hide()` 黑名单与 `show()` 白名单分离，防「先 hide 后 show」重新暴露 |

## 验证

- `moo-scaffold` 全量 **188 failed / 1153 passed**（= 基线）。
- 新增 `checkAuthorization()` 回归用例：还原旧写法实测变红、修复后变绿。
- 宿主实测：`app(OperatorResolver::class)` → `GetUserIdOperatorResolver`；`isPlatformRoot(root)=true`、
  `(null)=false`、`(普通)=false`。
