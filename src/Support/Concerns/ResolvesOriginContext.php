<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Concerns;

use Mooeen\Scaffold\Support\TargetContext;

/**
 * plan-53 出身(origin)工具:Generator / Adder 共用。
 * cache 条目挂 origin(null=host / 包 key);包 schema 的生成物按 TargetContext
 * 落包目录、用包命名空间(平铺,无 module folder 段)。宿主类须持有 $utility。
 */
trait ResolvesOriginContext
{
    /** 当前 schema 的出身上下文:null = host;包 schema 时由使用方在入口解析赋值 */
    protected ?TargetContext $originCtx = null;

    /** 出身上下文:host(null)返回 null,包返回 TargetContext */
    protected function originContext(?string $origin): ?TargetContext
    {
        return $origin === null ? null : $this->utility->targetContext($origin);
    }

    /**
     * 写权硬线(生成侧):包生成物写包目录,vcs 拷贝包(非软链)拒绝 —— 与 SchemaLoader /
     * SnapshotStore 同一条线。host 恒放行。
     */
    protected function assertOriginWritable(?string $origin): void
    {
        if ($origin !== null && ! $this->utility->targetContext($origin)->writable) {
            throw new \InvalidArgumentException("扩展包 [{$origin}] 是 vendor 拷贝(非软链安装),生成物只读 —— 请在软链装该包的开发环境生成。");
        }
    }

    /**
     * 生成物展示路径:host 内 → `./相对路径`(旧口径不变);包内 → `[包key]/包内相对路径`。
     */
    protected function relDisplay(string $abs, ?TargetContext $ctx = null): string
    {
        if ($ctx !== null && ! $ctx->isHost() && str_starts_with($abs, $ctx->basePath)) {
            return "[{$ctx->target}]/" . substr($abs, strlen($ctx->basePath));
        }

        return str_replace(base_path(), '.', $abs);
    }
}
