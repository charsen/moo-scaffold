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
}
