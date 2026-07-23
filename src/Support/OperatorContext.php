<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Closure;

/**
 * OperatorContext —— 队列 / CLI 等「无登录态」语境下显式声明当前操作人的进程内上下文（plan 42 · D3）。
 *
 * 背景：HasOperator 的 creator_id / updater_id 自动填充经 OperatorResolver 取「当前操作人」，
 * 而默认 OperatorResolver 读登录态（auth()->id() / host 的 getUserId()）——到了 queue worker /
 * artisan command 里没有登录态，恒 null，写库把操作人字段污染成 null/0。本类补的就是这个缺。
 *
 * 惯例（D3）：需要在无登录态语境写 HasOperator model 时——
 * ① 派发前把操作人快照成标量（如 `$this->operatorId = getUserId()`）；
 * ② handle 内 `OperatorContext::runAs($this->operatorId, fn () => ...写库段...)`。
 * HasOperator 两钩子优先消费本上下文：`OperatorContext::current() ?? app(OperatorResolver::class)->id()`。
 *
 * 语义：`runAs(null, ...)` = 显式「无上下文」，消费方按 `?? ` 回落 OperatorResolver——
 * 不区分「未设 context」与「设为 null」，消费端保持一行 `?? `。
 *
 * ⚠ 禁止在 HTTP 请求业务代码里用它冒充他人身份：它补的是「无登录态」的缺，不是「越权改身份」的口子。
 */
final class OperatorContext
{
    /**
     * 当前显式操作人上下文；未设时为 null。
     */
    private static int|string|null $current = null;

    /**
     * 当前显式操作人上下文；未设返回 null（消费方据此回落 OperatorResolver）。
     */
    public static function current(): int|string|null
    {
        return self::$current;
    }

    /**
     * 在「操作人 = $id」的上下文内执行 $fn 并返回其结果。
     *
     * 嵌套安全：保存进入前的上下文、`finally` 恢复外层；异常安全：$fn 抛异常也恢复。
     * `$id === null` = 显式无上下文（消费方回落 OperatorResolver）。
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $fn
     *
     * @return TReturn
     */
    public static function runAs(int|string|null $id, Closure $fn): mixed
    {
        $previous      = self::$current;
        self::$current = $id;

        try {
            return $fn();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * 复位上下文（测试用）。
     */
    public static function clear(): void
    {
        self::$current = null;
    }
}
