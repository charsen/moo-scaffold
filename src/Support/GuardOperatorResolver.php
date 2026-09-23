<?php declare(strict_types=1);
/*
 * GuardOperatorResolver —— OperatorResolver 的 scaffold 默认实现（B-01 方案 B）。
 *
 * 默认 guard 登录态；未登录返回 null。
 * host 想要非默认 guard / getUserId 语义 / 未登录 0 兜底，在自己的 provider bind 覆盖本类即可。
 */

namespace Mooeen\Scaffold\Support;

use Mooeen\Scaffold\Contracts\OperatorResolver;

class GuardOperatorResolver implements OperatorResolver
{
    public function id(): int|string|null
    {
        return auth()->id();
    }

    /**
     * 框架层不知道谁是平台 root，默认一律 false（最小权限）；
     * 宿主覆盖绑定后由宿主人员模型判定。
     */
    public function isPlatformRoot(int|string|null $operatorId): bool
    {
        return false;
    }
}
