<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * plan-52:文档深链 shortcode 解析后的 inline 节点。
 * kind ∈ debug|api|db|error；error 时 url 为 null、label 是错误说明。
 */
final class DocShortcodeNode extends AbstractInline
{
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly bool $isError = false,
    ) {
        parent::__construct();
    }
}
