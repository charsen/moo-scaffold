<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use DOMDocument;
use DOMElement;

/** 复用安全 Markdown 输出，仅为计划阅读页补充目录内链接和稳定标题锚点。 */
final class PlansMarkdownRenderer
{
    public function __construct(private readonly DocMarkdownRenderer $renderer) {}

    /** @param list<string> $slugs 已通过目录边界检查的可阅读文件 */
    public function render(string $markdown, string $current, array $slugs): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $this->renderer->render($markdown) . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $ids = [];
        foreach ((new \DOMXPath($document))->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $base = mb_strtolower(trim($heading->textContent));
            $base = preg_replace('/[^\p{L}\p{N}\p{M}_\-\s]/u', '', $base) ?? '';
            $base = preg_replace('/\s/u', '-', $base) ?: 'section';
            $id   = $base;
            for ($suffix = 1; isset($ids[$id]); $suffix++) {
                $id = $base . '-' . $suffix;
            }
            $ids[$id] = true;
            $heading->setAttribute('id', $id);
        }
        $allowed = array_fill_keys($slugs, true);
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $href)) {
                continue;
            }
            $parts    = parse_url($href);
            $path     = $parts            === false ? '' : rawurldecode($parts['path'] ?? '');
            $segments = dirname($current) === '.' ? [] : explode('/', dirname($current));
            $valid    = $path !== '' && ! preg_match('/[\\\\\x00-\x1f]/', $path) && ! str_starts_with($path, '/');
            foreach (explode('/', $path) as $segment) {
                if ($segment === '.' || $segment === '') {
                    continue;
                }
                if ($segment === '..') {
                    if ($segments === []) {
                        $valid = false;
                        break;
                    }
                    array_pop($segments);
                } else {
                    $segments[] = $segment;
                }
            }
            $target = implode('/', $segments);
            if ($valid && isset($allowed[$target])) {
                $url = route('plans.index', ['doc' => $target]);
                if (isset($parts['fragment'])) {
                    $url .= '#' . rawurlencode(rawurldecode($parts['fragment']));
                }
                $link->setAttribute('href', $url);
            } else {
                $this->disableLink($link);
            }
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    private function disableLink(DOMElement $link): void
    {
        $link->removeAttribute('href');
        $link->setAttribute('aria-disabled', 'true');
        $link->setAttribute('title', '目标不在可阅读的 plans 文档中');
    }
}
