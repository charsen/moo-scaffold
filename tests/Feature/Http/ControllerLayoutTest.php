<?php declare(strict_types=1);

/**
 * 控制器类布局锚点：属性声明必须集中在类顶部（PSR-12 的顺序：use → 常量 → 属性 → 方法）。
 *
 * 为什么钉这个：`RouteController` 原先把 3 个 memo 属性（`$apiSchemaCache` /
 * `$controllerMethodsCache` / `$controllerFileCache`）声明在方法之间 —— 读这个类时得翻到
 * 第 72 / 74 / 295 行，才知道「这个类到底有哪些状态」。这不会报任何错，只是持续消耗
 * 读代码的人（同族问题见 NOTES 里「同名类拆歧义」的理由）。
 *
 * 扫描口径：剥注释后按「4 空格缩进 + 可见性 + $变量」认属性、「4 空格缩进 + function」认方法，
 * 断言最后一个属性出现在第一个方法之前。**构造器提升属性（8 空格缩进）不参与判定** ——
 * 它们写在 `__construct` 签名里，算依赖不算类状态。
 *
 * 扫描范围 = **整个 `src/`**（2026-09-18 扩围）。原先只扫 `src/Http/Controllers/`，因为当时处理的
 * 就是控制器；扩围前用 token 扫描实测全 `src/` 只有 2 处同类写法（`Support/DocsRepository.php` 的
 * `$allCache`、`Support/AclDocumentLoader.php` 的 `$indexCache`，都已上移），此时扩围是零成本的防复发。
 */

/** 剥注释后的源码（正则剥注释会被字符串里的 `/*` 带偏，见 ReadonlyModeTest / PathsTest 的坑）。 */
function controller_layout_code_without_comments(string $path): string
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

it('类属性声明集中在方法之前（不得散落在方法之间）', function () {
    $offenders = [];
    $root      = dirname(__DIR__, 3) . '/src';

    foreach (\Illuminate\Support\Facades\File::allFiles($root) as $file) {
        $path       = $file->getPathname();
        $seenMethod = false;

        foreach (explode("\n", controller_layout_code_without_comments($path)) as $line) {
            if (! $seenMethod
                && preg_match('/^\s{4}(?:(?:final|public|protected|private|static)\s+)*function\s+\w+/', $line)) {
                $seenMethod = true;
            }
            if ($seenMethod
                && preg_match('/^\s{4}(?:private|protected|public)(?:\s+readonly)?\s+(?:static\s+)?(?:\??[\w\\\\]+)\s+\$(\w+)/', $line, $m)) {
                $offenders[] = str_replace($root . '/', '', $path) . ' → 属性 $' . $m[1];
            }
        }
    }

    expect($offenders)->toBe([], "以下文件的属性声明落在方法之后（应上移到类顶部）：\n  " . implode("\n  ", $offenders));
});
