<?php declare(strict_types=1);
/*
 * PersonnelNameResolver —— 跨包「批量人员 ID → 展示名」读时解析契约（2026-09-18）。
 *
 * 背景：moo-attachment（上传人）、moo-collect（收藏人）、moo-like（点赞人）、moo-comment（评论人）、
 * moo-mini-app（台账创建人/当前负责人）、moo-banner / moo-certificate / moo-cms / moo-product（操作人）、
 * moo-feedback（提交人）各自定义过**签名完全一致**的 `resolveNames(array $ids): array` 契约；
 * 每个 host 又各写一份等价实现（wisdomcity / xing-ke 的 `App\Moo\Support\PersonnelNameResolver`、
 * tcaweb-v2 的 `PersonnelSubmitterResolver`）。契约留各包、实现留 host 的做法本身没错，
 * 重复的是**形状**：收成 scaffold 这一份之后，包只认它、host 只实现一次。
 *
 * 为什么落在 scaffold：本生态**所有包都 require scaffold**，而**没有任何包 require moo-system**
 * （组织数据由 host 经 `Mooeen\System\Contracts\OrgDirectory` 组合）。scaffold 是唯一既能被包依赖、
 * 又不反向耦合某个 host 数据模型的位置。
 *
 * 实现方必须守住的语义：
 *   - **批量一次**：入参是 ID 数组，返回以 ID 为键的映射；禁止逐 ID 查询（读侧都是 N+1 高发场景）。
 *   - **读时解析、不落库**：只用于展示（列表/详情/导出把裸 ID 变成人名）；不写回业务表，
 *     也不作为授权判定依据 —— 权限一律实时经组织契约判定。
 *   - **缺失键缺省**：解析不到的 ID 不出现在返回里，由调用方自行 `?? null` 兜底；
 *     不得返回空串、`—`、`未知` 之类的占位名冒充解析结果。
 *   - **历史可读**：人员已离职/软删时，只要记录仍在，仍应返回姓名（审计与历史留痕的可读性优先）。
 *   - **不做有效性过滤**：本契约回答「这些 ID 现在叫什么」，不回答「他们能不能被指派」——
 *     后者属组织契约的 `filterAssignablePersonnelIds()` 类方法。
 *
 * 与其他缝的分工：`Contracts\OperatorResolver` 回答「当前操作人 ID」（写路径、单值）；
 * 本契约回答「这些 ID 现在叫什么」（读路径、批量）。**写时快照是另一件事**：
 * moo-trail 的 `ActorResolver` 在写入时固化「姓名 + 部门」，不随组织数据变化，
 * 不要用本契约替代快照，也不要拿快照当读时解析。
 *
 * 默认实现：scaffold **刻意不提供**。未绑定时容器抛错，是「host 忘了接线」的显式失败；
 * 给个空实现会静默把全站人名变成空白（回归测试钉住了这一点）。
 */

namespace Mooeen\Scaffold\Contracts;

interface PersonnelNameResolver
{
    /**
     * 批量解析人员 ID → 展示名。
     *
     * @param array<int|string|null> $ids 人员 ID 列表；空数组返回空数组
     *
     * @return array<int|string, string> `[人员 ID => 姓名]`；解析不到的 ID 键缺省，顺序不构成契约
     */
    public function resolveNames(array $ids): array;
}
