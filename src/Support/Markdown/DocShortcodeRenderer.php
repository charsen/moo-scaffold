<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * plan-52:把 DocShortcodeNode 渲染成可点 chip（深链 target=_blank 开新窗），error 渲染成红 chip。
 * 图标用内联 stroke SVG（trusted 常量），label 文本转义。
 */
final class DocShortcodeRenderer implements NodeRendererInterface
{
    /** 16×16 stroke SVG（currentColor），对齐 icon 组件风格。 */
    private const ICONS = [
        'debug' => '<svg class="doc-shortcode__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'api'   => '<svg class="doc-shortcode__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'db'    => '<svg class="doc-shortcode__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
        'error' => '<svg class="doc-shortcode__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    ];

    private const TITLES = [
        'debug' => '接口调试（新窗口打开）',
        'api'   => '接口文档（新窗口打开）',
        'db'    => '数据库文档（新窗口打开）',
    ];

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string
    {
        if (! $node instanceof DocShortcodeNode) {
            throw new InvalidArgumentException('Incompatible node type: ' . $node::class);
        }

        if ($node->isError || $node->url === null) {
            return new HtmlElement(
                'span',
                ['class' => 'doc-shortcode doc-shortcode--error', 'title' => $node->label],
                self::ICONS['error'] . '<span class="doc-shortcode__label">' . $this->esc($node->label) . '</span>',
            );
        }

        $icon  = self::ICONS[$node->kind] ?? '';
        $inner = $icon . '<span class="doc-shortcode__label">' . $this->esc($node->label) . '</span>';

        return new HtmlElement('a', [
            'href'   => $node->url,
            'target' => '_blank',
            'rel'    => 'noopener',
            'class'  => 'doc-shortcode doc-shortcode--' . $node->kind,
            'title'  => self::TITLES[$node->kind] ?? '',
        ], $inner);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
