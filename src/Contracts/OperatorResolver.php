<?php declare(strict_types=1);
/*
 * OperatorResolver —— 操作人身份注入缝（B-01 方案 B，2026-07-16）。
 *
 * 生成的 HasOperator（creator_id / updater_id 自动填充）经此契约取「当前操作人 ID」，
 * 不再把身份来源焊死在 auth() 门面。scaffold 默认绑 Support\GuardOperatorResolver（auth()->id()，
 * 未登录返回 null，与旧 stub 逐位一致）；host 可在自己的 provider bind 覆盖（如换 guard、getUserId 语义、0 兜底）。
 *
 * 契约约束：容器绑定，禁 config 闭包（config:cache 序列化闭包会炸生产）。
 */

namespace Mooeen\Scaffold\Contracts;

interface OperatorResolver
{
    /**
     * 当前操作人 ID；未登录返回 null。
     */
    public function id(): int|string|null;

    /**
     * 该操作者是否为**平台 root**（超级管理员）。
     *
     * 与 `id()` 同属「操作者身份」这一件事，故并入**同一个**契约：身份只应有**一份**宿主绑定，
     * 不允许各业务包再自造同义契约与适配器（2026-09-22 收敛 —— 原先 `moo-<name>` 自持
     * `Contracts\PlatformAccess`，宿主另写一份适配器，而同包文档本就写明「身份走 scaffold 共享
     * `OperatorResolver`，本包不做契约、不做绑定」）。
     *
     * 语义边界：只回答身份，**不授予**任何权限、数据范围或业务能力；调用方拿到 true 后自行决定
     * 开放范围。传 null（无操作者 / 游客）必须返回 false。
     *
     * scaffold 默认实现返回 false（框架层不知道谁是 root）；宿主在自己的 provider 覆盖绑定即可 ——
     * 典型实现是转调宿主人员模型的 `isRootId()`。
     */
    public function isPlatformRoot(int|string|null $operatorId): bool;
}
