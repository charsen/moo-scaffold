<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Validation\ValidationException;

/** 复用文档中心的 frontmatter 拆分，保留计划 / 发版记录的既有回退规则。 */
final class RecordMarkdownDocument
{
    public function __construct(private readonly MarkdownFrontmatter $frontmatter) {}

    /** @return array{title:string,group:string,order:int,tags:list<string>,body:string,error:?string} */
    public function parse(string $raw, string $slug, string $defaultGroup): array
    {
        $parsed = $this->frontmatter->parse($raw);
        $meta   = $parsed['meta'];
        $body   = preg_replace('/<!--.*?-->/s', '', $parsed['body']) ?? $parsed['body'];
        $title  = $this->text($meta['title'] ?? null);
        if ($title === '') {
            $title = preg_match('/^#[ \t]+(.+?)[ \t]*#*[ \t]*$/m', $body, $heading) ? trim($heading[1]) : $slug;
        }
        $group = $this->text($meta['group'] ?? null) ?: $defaultGroup;
        $value = $meta['order'] ?? null;
        $order = is_int($value) || (is_string($value) && preg_match('/\A[+-]?[0-9]+\z/', $value))
            ? filter_var($value, FILTER_VALIDATE_INT) : false;
        $error = $parsed['error'];
        if ($value !== null && $order === false) {
            $error ??= 'Frontmatter 的 order 必须是整数，不能使用布尔值、小数或其他类型。';
        }
        $tags = $meta['tags'] ?? [];
        if (is_string($tags)) {
            $tags = preg_split('/[,，]/u', $tags) ?: [];
        }
        $tags = is_array($tags) ? array_values(array_unique(array_filter(array_map($this->text(...), $tags), static fn ($tag) => $tag !== ''))) : [];

        return ['title' => $title, 'group' => $group, 'order' => $order === false ? 999 : $order, 'tags' => $tags, 'body' => $body, 'error' => $error];
    }

    public function assertValid(string $raw): void
    {
        $error = $this->parse($raw, '', '')['error'];
        if ($error !== null) {
            throw ValidationException::withMessages(['content' => $error]);
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }
}
