<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** 只拆分用于展示的内容；调用方保存原文，不重新序列化 YAML。 */
final class MarkdownFrontmatter
{
    /** @return array{meta:array,body:string,error:?string} */
    public function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! preg_match('/\A---[\t ]*\n/', $raw)) {
            return ['meta' => [], 'body' => $raw, 'error' => null];
        }
        if (! preg_match('/\A---[\t ]*\n(.*?)^---[\t ]*(?:\n|\z)(.*)\z/ms', $raw, $match)) {
            return ['meta' => [], 'body' => $raw, 'error' => 'Frontmatter 第 1 行：缺少独立一行的结束标记 ---。'];
        }

        try {
            $meta = Yaml::parse($match[1]) ?? [];
        } catch (ParseException $e) {
            $line = max(1, $e->getParsedLine()) + 1;

            return ['meta' => [], 'body' => $match[2], 'error' => "Frontmatter 第 {$line} 行：YAML 语法错误，请检查括号、引号和缩进。"];
        }
        if (! is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            return ['meta' => [], 'body' => $match[2], 'error' => 'Frontmatter 第 2 行：头部必须是 YAML 键值对象。'];
        }

        return ['meta' => $meta, 'body' => $match[2], 'error' => null];
    }
}
