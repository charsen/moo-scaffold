<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * OperatorId —— 「无效操作人哨兵」标量归一（plan 42 · Phase F）。
 *
 * 三包（moo-trail / moo-attachment / moo-radar）此前各写一份「操作人 id 无效即解析失败」的
 * 哨兵判断，口径微漂移（trail 判 null/0/'0'/全零串、attachment/radar 判 null/''/0/'0'）。
 * 本类把三家判断收敛成共享单点：把无效哨兵归一为 null、其余原样透传，各包在此之上保留自己的
 * 额外处置（trail 的 positiveId 正数/范围收紧、attachment/radar 的 AuthenticationException）。
 *
 * 归一为 null 的哨兵 = 三家判断的并集：
 *   - null           —— 三家共有
 *   - ''（空串）      —— attachment / radar 共有
 *   - 0（int 零）     —— 三家共有
 *   - '0'（字符串零）  —— 三家共有
 *   - 全零字符串 '00…' —— trail 现有语义（ctype_digit 且 ltrim('0') 为空）
 *
 * 其余值（正整数 / 雪花字符串 / 前导零非零串 '007' / 负数 / 非数字串 …）一律原样返回：
 * 本类只归一「明确无效」的哨兵，不做正数/范围校验（那是 trail positiveId 的活）——
 * 负数与非数字串透传给调用方，由各包既有逻辑各自处置（trail positiveId 抛、attachment/radar 放行）。
 */
final class OperatorId
{
    /**
     * 把无效操作人哨兵归一为 null，其余原样返回。
     *
     * 调用方约定传入 int|string|null（三处消费点均取自 OperatorResolver::id() 或显式标量）；
     * 非标量属编程错误，透传时由返回类型显式失败。
     */
    public static function normalize(mixed $value): int|string|null
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        // 全零字符串（'00' / '000' …）：trail 既有语义视作无效操作人。
        if (is_string($value) && ctype_digit($value) && ltrim($value, '0') === '') {
            return null;
        }

        return $value;
    }
}
