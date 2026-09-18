<?php

declare(strict_types=1);

/**
 * 命令层输出出口锚点：所有控制台输出都必须走 `$this->console()`。
 *
 * 为什么钉这个：收口前同一语义有两套写法 —— `$this->console()->error()` 渲染 `❌  消息`，
 * 原生 `$this->error()` 渲染 Laravel 的红底 `ERROR` 块；`info()` 同理（`ℹ️  ` vs 裸文本）。
 * 两者**都不报错**，所以混用不会以任何失败的形式暴露自己，只能靠锚点挡住它被写回来。
 *
 * 真实 Command 目标下 line() / table() 的行为由 ComposerDocsCommandTest（line）、
 * AuditFormerTypesCommandTest / AuditResourceKeysCommandTest（table）覆盖；本文件只管「用没用对出口」。
 *
 * 有意例外（在此登记，不进扫描）：
 *   - `src/Http/Controllers/DesignerController::error()` 是 HTTP JSON 响应助手，与控制台无关；
 *   - `src/Support/Markdown/DocShortcodeResolver::error()` 是它自己的错误收集器；
 *   - `src/Utility::addGitIgnore($command)` 仍 `new ConsoleUi($command)` —— 它是 Utility 的公开方法
 *     且参数无类型，改成收 ConsoleUi 属公开签名变更，留待 Utility 拆分时一并处理。
 */
it('命令层与 RouterTool 不直连原生输出，一律走 console()', function () {
    $root = dirname(__DIR__, 3);

    $targets = array_merge(
        glob($root . '/src/Command/*.php') ?: [],
        [$root . '/src/RouterTool.php'],
    );

    expect($targets)->not->toBeEmpty();

    $offenders   = [];
    $routedFiles = [];

    foreach ($targets as $path) {
        $src = (string) file_get_contents($path);
        $rel = str_replace($root . '/', '', $path);

        if (str_contains($src, 'console()->')) {
            $routedFiles[] = $rel;
        }

        // 注释里写 `$this->line()` 作举例不算违规，这里只看代码行。
        $code = (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', $src);

        if (preg_match_all('/\$this->(line|newLine|table|error|warn|info|success)\(/', $code, $m)) {
            $offenders[] = $rel . ' → ' . implode(', ', array_unique($m[1]));
        }
    }

    expect($offenders)->toBe([], "以下文件绕过 ConsoleUi 直连原生输出（应改走 \$this->console()->…）：\n  " . implode("\n  ", $offenders));

    // 正向锚点：确认扫描真的扫到了走 console() 的命令，而不是文件集为空导致空过。
    expect($routedFiles)->toContain('src/Command/Command.php', 'src/Command/FreeCommand.php', 'src/RouterTool.php');
});
