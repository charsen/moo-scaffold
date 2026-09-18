<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ReadonlyMode;

/**
 * 「scaffold 是否只读」的唯一口径（`Support\ReadonlyMode`）。
 *
 * 为什么钉这个：收口前这套判定在 Support 与 Http 两侧被抄了多份（含 `DocsRepository::isReadonly()`
 * 这份全仓零调用的死代码），而且写法不一致 —— 有的带 `function_exists('app')` 守卫、有的读注入的
 * `Repository`、有的读全局 `config()`。只读是**公开安全契约**（生产环境后端写路由与 writer 必须
 * 拒绝执行，不只是隐藏按钮），口径分叉不会报错、只会静默失效，所以除了行为用例外，
 * 还要一个「只读判定的两个字面只允许出现在 ReadonlyMode 里」的锚点。
 */

/** 把 APP_ENV 临时置为 $env 跑一段代码，跑完还原。 */
function readonly_mode_in_env(string $env, Closure $fn): mixed
{
    $orig = app()->environment();
    app()->instance('env', $env);

    try {
        return $fn();
    } finally {
        app()->instance('env', $orig);
    }
}

/** 剥掉注释后的源码（注释里提这两个字面属说明，不算违规）。 */
function readonly_mode_code_without_comments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= $token[1];
            }

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('productionActive()：仅 APP_ENV=production 为真', function () {
    expect(readonly_mode_in_env('production', fn () => ReadonlyMode::productionActive()))->toBeTrue();
    expect(readonly_mode_in_env('local', fn () => ReadonlyMode::productionActive()))->toBeFalse();
    expect(readonly_mode_in_env('staging', fn () => ReadonlyMode::productionActive()))->toBeFalse();
});

it('configLocked()：仅 config_ui.readonly=true 为真', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(ReadonlyMode::configLocked())->toBeTrue();

    config(['scaffold.config_ui.readonly' => false]);
    expect(ReadonlyMode::configLocked())->toBeFalse();
});

it('active()：两个来源任一为真即为真，都假才为假', function () {
    readonly_mode_in_env('local', function () {
        config(['scaffold.config_ui.readonly' => false]);
        expect(ReadonlyMode::active())->toBeFalse();

        config(['scaffold.config_ui.readonly' => true]);
        expect(ReadonlyMode::active())->toBeTrue();
    });

    readonly_mode_in_env('production', function () {
        // 生产即使没开强制只读开关也是只读 —— 这条最容易在重构里被漏掉。
        config(['scaffold.config_ui.readonly' => false]);
        expect(ReadonlyMode::active())->toBeTrue();
    });
});

it('只读判定锚点：口径只在 ReadonlyMode 里实现', function () {
    $root      = dirname(__DIR__, 3);
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = str_replace('\\', '/', str_replace($root . '/', '', $file->getPathname()));
        if ($rel === 'src/Support/ReadonlyMode.php') {
            continue;     // 口径本体
        }

        $code = readonly_mode_code_without_comments($file->getPathname());

        foreach (['scaffold.config_ui.readonly', "environment('production')"] as $literal) {
            if (str_contains($code, $literal)) {
                $offenders[] = "{$rel} → {$literal}";
            }
        }
    }

    expect($offenders)->toBe([], "以下文件自己实现了只读判定（应改用 Support\\ReadonlyMode）：\n  " . implode("\n  ", $offenders));
});
