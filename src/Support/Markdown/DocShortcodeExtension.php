<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * plan-52:注册文档 shortcode 的 inline parser + node renderer。
 */
final class DocShortcodeExtension implements ExtensionInterface
{
    public function __construct(private readonly DocShortcodeResolver $resolver) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new DocShortcodeParser($this->resolver), 200)
            ->addRenderer(DocShortcodeNode::class, new DocShortcodeRenderer);
    }
}
