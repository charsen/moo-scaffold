<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use InvalidArgumentException;

/**
 * 多目标(multi-target)解析快照:把"代码 / 文档 / schema 落到哪个包、用什么命名空间根"
 * 一次性算成不可变值对象。**host 是隐含默认 target**(target === null,沿用现有 host 路径)。
 *
 * 由 Utility::targetContext() 构建;generator / designer / docs 在入口解析一次后往下传用,
 * 不在共享单例上存可变"当前 target"(避免跨 target 串写——见 plan 决策 2)。
 */
final class TargetContext
{
    /**
     * @param array<string,string> $paths      kind(model/controller/migration/database/docs/...) => 输出路径
     * @param array<string,string> $namespaces kind => 完整命名空间前缀(如 Mooeen\System\Models)
     * @param array<string,mixed>  $classes    基类 FQCN 覆盖(空 = 继承 host class.*)
     * @param bool                 $writable   写权硬线(plan-53):包软链装 = true;vcs 拷贝 = false。host 恒 true
     *                                         (env 只读另有 EnforceScaffoldWritable 管,两轴独立)
     */
    public function __construct(
        public readonly ?string $target,
        public readonly string $basePath,
        public readonly array $paths,
        public readonly array $namespaces,
        public readonly ?string $app = null,
        public readonly array $classes = [],
        public readonly bool $writable = true,
    ) {}

    public function isHost(): bool
    {
        return $this->target === null;
    }

    /**
     * 取某类产物的输出路径。目录类 kind 配置带尾 `/`、文件类(如 route)不带——保持配置原样。
     * $sub 为模块子目录(如 `System`),非空时按目录拼接。
     */
    public function pathFor(string $kind, string $sub = ''): string
    {
        if (! isset($this->paths[$kind])) {
            throw new InvalidArgumentException("target [{$this->label()}] 未配置 [{$kind}] 路径");
        }

        $base = $this->paths[$kind];

        return $sub === '' ? $base : rtrim($base, '/') . '/' . ltrim($sub, '/');
    }

    /**
     * 取某类产物的命名空间前缀。$sub 为模块子段(如 `System`)。
     */
    public function namespaceFor(string $kind, string $sub = ''): string
    {
        if (! isset($this->namespaces[$kind])) {
            throw new InvalidArgumentException("target [{$this->label()}] 未配置 [{$kind}] 命名空间");
        }

        $ns = rtrim($this->namespaces[$kind], '\\');

        return $sub === '' ? $ns : $ns . '\\' . trim($sub, '\\');
    }

    private function label(): string
    {
        return $this->target ?? 'host';
    }
}
