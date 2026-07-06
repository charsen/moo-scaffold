<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 配置字段来源扫描器（plan 18 §3.2.3）
 *
 * 静态扫描 engine/config/scaffold.php 源码，匹配 env('KEY', default) 调用，
 * 建立 "config dot-path → env key" 映射。结果 cache 到 scaffold/.local/config-env-map.json，
 * 按源文件 mtime 失效自动重建。
 *
 * 为什么静态扫描：运行时反向推断不可靠（env() 调用结果可能被 cache，且无法从值反推 key）。
 *
 * 实现取舍：用正则而非 AST。
 *  - 优点：零依赖、足够覆盖 Laravel 标准 env() 写法
 *  - 不支持：动态 key、链式调用、变量插值（本配置文件不会出现这些）
 */
class ConfigSourceScanner
{
    private string $configFile;

    private string $cacheFile;

    public function __construct(?string $configFile = null, ?string $cacheFile = null)
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        $this->configFile = $configFile
            ?? $base . '/config/scaffold.php';

        $this->cacheFile = $cacheFile
            ?? $base . '/scaffold/.local/config-env-map.json';
    }

    /**
     * 返回 "dot-path => env-key" 映射；自动按 mtime 决定 cache 是否命中。
     */
    public function map(): array
    {
        if (! is_file($this->configFile)) {
            return [];
        }

        $cached = $this->loadCacheIfFresh();
        if ($cached !== null) {
            return $cached;
        }

        $map = $this->scan();
        $this->writeCache($map);

        return $map;
    }

    /**
     * 给定 dot-path 返回对应 env key；不存在返回 null。
     */
    public function envKeyOf(string $dotPath): ?string
    {
        return $this->map()[$dotPath] ?? null;
    }

    /**
     * 强制重新扫描，绕过 cache。
     */
    public function rebuild(): array
    {
        $map = $this->scan();
        $this->writeCache($map);

        return $map;
    }

    /**
     * 扫描实现：递归遍历 array，收集叶子节点的 dot-path；
     * 同时正则匹配 env('FOO', ...) 出现位置 → 对应同一行 / 同一层级的 key。
     *
     * 取巧做法：require 配置文件拿到完整数组结构，
     * 然后正则扫描源码拿到 env() 的字面字符串。
     * 用 "包含 env() 调用的行号" 反推所在的 dot-path。
     */
    private function scan(): array
    {
        $source = file_get_contents($this->configFile);
        if ($source === false) {
            return [];
        }

        // 第一遍：找出所有 `env('KEY'` 的字面 key 和所在行号
        $envHits = [];
        if (preg_match_all(
            "/env\\(\\s*['\"]([A-Z_][A-Z0-9_]*)['\"]/",
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[1] as $hit) {
                [$envKey, $offset] = $hit;
                $line              = substr_count(substr($source, 0, $offset), "\n") + 1;
                $envHits[$line]    = $envKey;
            }
        }

        if ($envHits === []) {
            return [];
        }

        // 第二遍：逐行扫描 PHP 数组键 'foo' => ...，维护括号层级 → dot-path 栈
        // 仅追踪 quote-style 数组键（'name' => / "name" =>）
        $lines      = explode("\n", $source);
        $stack      = [];        // 当前路径栈，e.g. ['controller', 'admin']
        $stackDepth = [];   // 每层栈对应的括号深度（哪一层 `[` 进入了它）
        $depth      = 0;
        $map        = [];

        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;

            // 这一行命中了 env() 调用？记录"当前栈 + 行尾的 key"
            // 注意：通常一行形如  `'timeout' => (int) env('SCAFFOLD_PROXY_TIMEOUT', 30),`
            // —— key 在行首出现，env 在右侧。所以先抓本行的 key，再决定挂在哪。

            // 抓本行所有 'name' =>（quoted）
            $keysOnLine = [];
            if (preg_match_all(
                '/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>/',
                $line,
                $m
            )) {
                $keysOnLine = $m[1];
            }

            // 行内同时命中 env() 和 'key' =>：把该 key 挂到当前 stack 下
            // env() 出现在没有同行 key 的延续表达式（如多行 ternary）→ 跳过，
            // 因为同 binding 的"主行"已经捕获过了
            if (isset($envHits[$lineNo]) && $keysOnLine !== []) {
                $envKey                   = $envHits[$lineNo];
                $leafKey                  = end($keysOnLine);
                $path                     = $stack;
                $path[]                   = $leafKey;
                $map[implode('.', $path)] = $envKey;
            }

            // 更新栈：按本行 [ / ] / { / } 配对，调整 depth；
            // 这里仅追踪 `[`（数组开始）和 `]`（结束）。
            // 当遇到 `'name' => [` 的形式 → push key 到栈。
            $this->updateStack($line, $stack, $stackDepth, $depth);
        }

        return $map;
    }

    /**
     * 解析一行：根据其中的 `[` / `]` 调整路径栈和括号深度。
     */
    private function updateStack(string $line, array &$stack, array &$stackDepth, int &$depth): void
    {
        // 先抹掉字符串字面，避免误判 ']' '['
        $clean = preg_replace('/\'[^\']*\'|"[^"]*"/', '', $line) ?? $line;
        // 抹掉单行注释
        $clean = preg_replace('!//.*$!', '', $clean) ?? $clean;
        $clean = preg_replace('!#.*$!', '', $clean)  ?? $clean;

        // 在已抹掉字符串的视图上重新找 key （挂载用 key 用 cleaner 版本但行内顺序保持）
        // 我们这里只关心括号变化，对 key 的提取上面已在调用处做过。

        // 提取本行 `'name' =>` 的 key（与上面相同，但用 clean 版避免字符串内的箭头）
        $pendingKeys = [];
        if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>/', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $pendingKeys[] = ['key' => $hit[0], 'offset' => $hit[1]];
            }
        }

        // 遍历 clean 里的 [ / ]，维护 depth；遇到 `[` 时如果它前面紧邻一个 pending key（`'x' => [`），
        // 把该 key push 到 stack
        $len    = strlen($clean);
        $keyIdx = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $clean[$i];
            if ($ch === '[') {
                // 这个 [ 是不是被一个 pending key 紧邻 `=>` 引入的？
                $pushedKey = null;
                while ($keyIdx < count($pendingKeys) && $pendingKeys[$keyIdx]['offset'] < $i) {
                    $pushedKey = $pendingKeys[$keyIdx]['key'];
                    $keyIdx++;
                }
                if ($pushedKey !== null) {
                    $stack[]      = $pushedKey;
                    $stackDepth[] = $depth + 1;
                }
                $depth++;
            } elseif ($ch === ']') {
                $depth = max(0, $depth - 1);
                // 弹出所有 stackDepth > 当前 depth 的层
                while ($stackDepth !== [] && end($stackDepth) > $depth) {
                    array_pop($stack);
                    array_pop($stackDepth);
                }
            }
        }
    }

    private function loadCacheIfFresh(): ?array
    {
        if (! is_file($this->cacheFile)) {
            return null;
        }

        $cacheMtime  = filemtime($this->cacheFile);
        $sourceMtime = filemtime($this->configFile);

        // <=:mtime 是秒粒度,源文件在 cache 写出的同一秒内被改 → < 误判新鲜、吃到旧映射
        // (实测在测试套件里 flake;重扫只是单文件正则,便宜,2026-06-10 修)
        if ($cacheMtime === false || $sourceMtime === false || $cacheMtime <= $sourceMtime) {
            return null;
        }

        $raw = file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(array $map): void
    {
        $dir = dirname($this->cacheFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // plan-40 §三 R-1 横切补漏:multi-tab 同时刷 /scaffold/config 可能并发写撕裂 JSON
        @file_put_contents(
            $this->cacheFile,
            json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
