<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * plan-52:行内解析 [[type: target | label]] shortcode。
 * 命中后交给 DocShortcodeResolver 转成 DocShortcodeNode（由 DocShortcodeRenderer 渲染）。
 */
final class DocShortcodeParser implements InlineParserInterface
{
    public function __construct(private readonly DocShortcodeResolver $resolver) {}

    public function getMatchDefinition(): InlineParserMatch
    {
        // [[ type : target ( | label )? ]]  —— type 至少一个字母开头;target 不含 ] 和 |;label 不含 ]
        // 分隔符容忍前导反斜杠(\?\|):表格里 DocMarkdownRenderer 会把 | 预转义成 \|(GFM 切单元格
        // 不切转义管道),GFM 再反转义回字面 | 喂给行内解析 → 表格内/外同一套语法都命中(见该渲染器注释)。
        return InlineParserMatch::regex('\[\[\s*([a-zA-Z][\w-]*)\s*:\s*([^\]|]+?)\s*(?:\\\\?\|\s*([^\]]*?)\s*)?\]\]');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());

        [$type, $target, $label] = array_pad($inlineContext->getSubMatches(), 3, null);

        $node = $this->resolver->resolve((string) $type, (string) $target, $label);
        $inlineContext->getContainer()->appendChild($node);

        return true;
    }
}
