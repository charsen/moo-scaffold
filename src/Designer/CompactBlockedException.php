<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

/**
 * plan-49:migration 合并被某个 guard 拒绝
 *  - rename / drop 中间态
 *  - 已 push 到 remote
 *  - create 文件缺失 / 多重
 * GUI catch 后据 ->code() 显不同文案。
 */
class CompactBlockedException extends \RuntimeException
{
    public const REASON_RENAME = 'rename';

    public const REASON_DROP = 'drop';

    public const REASON_GIT_PUSHED = 'git_pushed';

    public const REASON_GIT_UNCERTAIN = 'git_uncertain';

    public const REASON_NO_CREATE = 'no_create';

    public const REASON_MULTI_CREATE = 'multi_create';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
