# BaseResource::hide() 累加修复（moo-scaffold 2.2.7 候选）

状态：**准备发版（未打 tag）** —— 修复已在 `dev` 与 `master`，工作区干净。

## 发布形态

- 本仓：待打 annotated patch tag **`2.2.7`** → `master` 顶点。
- **下游需注意**：`moo-system` 必须抬到 `^2.2.7` —— 它的 `AuthorizationController::index()`
  依赖本次的累加语义才能真正隐藏 `role_next_actions`（修复前只隐藏了一半）。
- 不包含服务器部署；其它仓的发版由各仓自行记录。

## 缺陷

`BaseResourceTrait::hide()` 是 `JsonResource::hide()` 的覆写，写的是本类 `$customFields`
（`filterFields()` 真正读取的字段），而原实现直接赋值：

```php
$this->customFields = $fields;        // 覆盖
```

于是**连续两次 `hide()` 只有最后一次生效**。

**真实事故**（`moo-system` 授权接口）：

```php
$resource = BaseResource::make($role)->hide('role_next_actions');   // 被覆盖
if (! $isRoot) $resource->hide(['role_actions']);                   // 只剩这个生效
```

`role_next_actions` 是 Admin ACL 的**真实落库列**（逗号串），与 `role_actions` 是同一份权限清单。
结果非 root 仍能读到他人角色的全量 key —— 实测 339 字符、20 个 key，而该用户的可见范围只有 705。
该防泄露修复此前**只生效了一半**，且**无任何测试覆盖**。

## 修复

```php
$this->customFields = array_values(array_unique([...$this->customFields, ...$fields]));
```

## 验证

真实 HTTP（root 与非 root 各一次，角色「方案库 - 数据录入员」）：

| 身份 | 权限列可见 | `granted_count` | `granted_out_of_scope` |
| --- | --- | --- | --- |
| root | 仅 `role_actions` ✅ | 13 | 0 |
| 非 root | **两列均已隐藏** ✅ | 13 | 0 |

新增回归测试 `tests/Feature/Foundation/BaseResourceHideTest.php`（3 条）：连续调用累加、
单次调用不退化、重复字段不产生重复项。

## 下游影响面（全仓扫描）

`->hide(` 调用点共 6 处，均只调用一次或正是本次要修的两连调用：

| 位置 | 调用次数 | 影响 |
| --- | --- | --- |
| `moo-system/AuthorizationController::index()` | 2（连用） | **正是本次修复目标** |
| `moo-system/RoleController::index()` | 1 | 无变化 |
| `某个内部 Host` `WorkController` / `MyWorkController` | 各 1 | 无变化 |
| `moo-scaffold` 自身（trait / collection） | — | 无变化 |

## 发版前剩余项

- [ ] 合入 `master`、打 annotated tag `2.2.7`、推送 `dev` + `master`、核对远端解引用。
- [ ] `moo-system` 抬约束到 `^2.2.7`。
