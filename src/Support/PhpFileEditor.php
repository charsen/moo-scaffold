<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * PHP 配置文件外科手术编辑器。
 *
 * 仅替换 `return [...]` 数组里**字面量叶子节点**的值，按 dot-path 定位。
 * 用 nikic/php-parser 拿到目标节点的 startFilePos / endFilePos，
 * 然后直接在源码字符串上做位置替换——不重排版、不丢注释、不动 env()/base_path() 等函数调用节点。
 *
 * 写入流程：
 *   1. parse 源文件
 *   2. 按 dot-path 找到 ArrayItem
 *   3. 校验 value 节点是字面量 / 简单数组（FuncCall / Variable 等抛错）
 *   4. 倒序应用位置替换（保持前一个替换的位置不被后面的影响）
 *   5. 再 parse 一次校验
 *   6. 原子写：临时文件 → rename
 *   7. opcache_invalidate
 *
 * 失败时原文件保持不动。
 */
class PhpFileEditor
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @param array<string, mixed> $writes dot-path => new value
     */
    public function setValuesInFile(string $filePath, array $writes): void
    {
        if ($writes === []) {
            return;
        }
        if (! is_file($filePath)) {
            throw new RuntimeException("文件不存在：{$filePath}");
        }

        $source = file_get_contents($filePath);
        if ($source === false) {
            throw new RuntimeException("读取失败：{$filePath}");
        }

        $stmts = $this->parser->parse($source);
        if ($stmts === null) {
            throw new RuntimeException("解析失败：{$filePath}");
        }

        $arrayNode = $this->locateReturnArray($stmts);
        if ($arrayNode === null) {
            throw new RuntimeException("文件根节点不是 `return [...]`：{$filePath}");
        }

        // 收集 [startPos, endPos, replacement] 三元组
        $replacements = [];
        foreach ($writes as $dotPath => $newValue) {
            $item = $this->findLeafItem($arrayNode, explode('.', (string) $dotPath));
            if ($item === null) {
                throw new RuntimeException("路径 [{$dotPath}] 在 {$filePath} 中不存在；只支持更新已有 key");
            }
            $value = $item->value;
            $this->assertLiteralValueNode($value, (string) $dotPath);

            $start = $value->getStartFilePos();
            $end   = $value->getEndFilePos();
            if ($start === -1 || $end === -1) {
                throw new RuntimeException("路径 [{$dotPath}] 节点无位置信息");
            }
            $replacements[] = [$start, $end, $this->exportLiteral($newValue)];
        }

        // 倒序替换：保持靠前位置的偏移有效
        usort($replacements, static fn ($a, $b) => $b[0] <=> $a[0]);
        $next = $source;
        foreach ($replacements as [$start, $end, $code]) {
            $next = substr($next, 0, $start) . $code . substr($next, $end + 1);
        }

        // 再 parse 校验
        try {
            $this->parser->parse($next);
        } catch (\Throwable $e) {
            throw new RuntimeException('写入后 PHP 语法错误，已回滚：' . $e->getMessage());
        }

        // 原子写
        $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $next) === false) {
            throw new RuntimeException("临时文件写入失败：{$tmp}");
        }
        if (! @rename($tmp, $filePath)) {
            @unlink($tmp);
            throw new RuntimeException("rename 失败：{$tmp} → {$filePath}");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($filePath, true);
        }
    }

    /**
     * 找到顶层 `return [...]` 的 Array_ 节点。
     */
    private function locateReturnArray(array $stmts): ?Node\Expr\Array_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * 沿 dot-path 下钻到目标 ArrayItem。
     */
    private function findLeafItem(Node\Expr\Array_ $array, array $pathParts): ?Node\ArrayItem
    {
        $head = array_shift($pathParts);
        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem || $item->key === null) {
                continue;
            }
            $keyText = $this->stringKeyOf($item->key);
            if ($keyText === null || $keyText !== $head) {
                continue;
            }
            if ($pathParts === []) {
                return $item;
            }
            if (! $item->value instanceof Node\Expr\Array_) {
                return null;
            }

            return $this->findLeafItem($item->value, $pathParts);
        }

        return null;
    }

    private function stringKeyOf(Node $keyNode): ?string
    {
        if ($keyNode instanceof Node\Scalar\String_) {
            return $keyNode->value;
        }
        if ($keyNode instanceof Node\Scalar\Int_) {
            return (string) $keyNode->value;
        }

        return null;
    }

    /**
     * 校验 value 节点是字面量或简单数组——拒绝 env() / base_path() / 变量等。
     */
    private function assertLiteralValueNode(Node $value, string $dotPath): void
    {
        if ($value instanceof Node\Scalar\String_) {
            return;
        }
        if ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_) {
            return;
        }
        if ($value instanceof Node\Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return;
            }
        }
        if ($value instanceof Node\Expr\UnaryMinus && (
            $value->expr instanceof Node\Scalar\Int_ || $value->expr instanceof Node\Scalar\Float_
        )) {
            return;
        }
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if (! $item instanceof Node\ArrayItem) {
                    continue;
                }
                $this->assertLiteralValueNode($item->value, $dotPath . '[]');
            }

            return;
        }

        throw new RuntimeException(
            "路径 [{$dotPath}] 的当前值是非字面量（如 env() / base_path() / 变量），不能从 UI 写入；" .
            '请直接编辑源文件。'
        );
    }

    /**
     * 把 PHP 值序列化为单行 PHP 代码片段（inline；保持文件风格紧凑）。
     */
    private function exportLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $this->exportString($value);
        }
        if (is_array($value)) {
            return $this->exportArrayInline($value);
        }

        throw new RuntimeException('不支持的值类型：' . get_debug_type($value));
    }

    private function exportString(string $s): string
    {
        return "'" . strtr($s, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    private function exportArrayInline(array $a): string
    {
        if ($a === []) {
            return '[]';
        }
        $isList = array_is_list($a);
        $parts  = [];
        foreach ($a as $k => $v) {
            $parts[] = $isList
                ? $this->exportLiteral($v)
                : $this->exportLiteral($k) . ' => ' . $this->exportLiteral($v);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
