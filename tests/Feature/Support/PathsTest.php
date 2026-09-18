<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\Paths;

/**
 * 路径归一的唯一口径（`Support\Paths`）。
 *
 * 为什么钉这个：收口前「相对 → 绝对」有两类需求、共 10 份手写实现，且两侧的**绝对性判定并不一致**
 * —— 命令里的 `absolutePath()` 认 Windows 盘符，配置项那 6 份只认 `/` 开头。后果是 Windows 上把
 * `C:\...` 写进配置项会被当成相对路径挂到 `base_path()` 下，静默落到错地方（不报错、只是找不着）。
 * 所以除了行为用例外，还要一个「这两类字面写法只允许出现在 Paths 里」的锚点。
 *
 * 注释里提这些字面属于说明，不算违规 —— 锚点用 token_get_all 剥注释后再扫，避免把文档字符串当违规。
 */

/** 剥掉注释后的源码（与 ReadonlyModeTest 同法：正则剥注释会被路由串 `/*` 里的内容吞掉真实代码）。 */
function paths_code_without_comments(string $path): string
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

it('isAbsolute()：`/` 开头 或 Windows 盘符 才算绝对', function () {
    expect(Paths::isAbsolute('/a/b'))->toBeTrue()
        ->and(Paths::isAbsolute('C:\\a\\b'))->toBeTrue()
        ->and(Paths::isAbsolute('C:/a/b'))->toBeTrue()
        ->and(Paths::isAbsolute('c:/a'))->toBeTrue()
        // 下列都不是绝对路径 —— 尤其 `C:` 后面没有分隔符的那种（相对当前盘目录）
        ->and(Paths::isAbsolute(''))->toBeFalse()
        ->and(Paths::isAbsolute('a/b'))->toBeFalse()
        ->and(Paths::isAbsolute('./a'))->toBeFalse()
        ->and(Paths::isAbsolute('../a'))->toBeFalse()
        ->and(Paths::isAbsolute('C:foo'))->toBeFalse()
        ->and(Paths::isAbsolute('~'))->toBeFalse();
});

it('join()：两侧斜杠去重，不做绝对性判断（base 尾斜杠结果仍带一个）', function () {
    expect(Paths::join('/base', 'sub'))->toBe('/base/sub')
        ->and(Paths::join('/base/', 'sub'))->toBe('/base/sub')
        ->and(Paths::join('/base', '/sub'))->toBe('/base/sub')
        ->and(Paths::join('/base/', '/sub/'))->toBe('/base/sub/')
        // 空 path 原实现就是「base 去尾斜杠 + /」，保持逐字节一致（调用方自己判空）
        ->and(Paths::join('/base/', ''))->toBe('/base/')
        ->and(Paths::join('/', 'a'))->toBe('/a');
});

it('absolute()：绝对原样，相对拼 base', function () {
    expect(Paths::absolute('/abs/x', '/base'))->toBe('/abs/x')
        ->and(Paths::absolute('C:\\abs', '/base'))->toBe('C:\\abs')
        ->and(Paths::absolute('rel/x', '/base/'))->toBe('/base/rel/x')
        ->and(Paths::absolute('/rel/x', '/base'))->toBe('/rel/x');
});

it('fromBasePath()：绝对原样，相对走 base_path()', function () {
    $abs = base_path('x');

    expect(Paths::fromBasePath($abs))->toBe($abs)
        ->and(Paths::fromBasePath('scaffold/schema'))->toBe(base_path('scaffold/schema'));
});

it('fromBasePath() 认盘符：Windows 绝对路径不再被挂到 base_path() 下（收口前的 bug）', function () {
    // 收口前这 6 处只判 `str_starts_with($x, '/')` → `C:\...` 会被当相对路径，
    // 静默变成 base_path('C:\...')。这是本类统一判定时**唯一有意改变的运行时行为**。
    expect(Paths::fromBasePath('C:\\scaffold\\schema'))->toBe('C:\\scaffold\\schema')
        ->and(Paths::fromBasePath('D:/work/schema'))->toBe('D:/work/schema');
});

it('路径口径锚点：绝对/相对判定与 base 拼接只在 Paths 里实现', function () {
    $root      = dirname(__DIR__, 3);
    $anchor    = 'src/Support/Paths.php';
    $offenders = [];

    // ① 「绝对 ? 原样 : base_path(x)」—— 应改用 fromBasePath()
    $ternary = '/\?\s*\$[\w>\[\]\'"]+\s*:\s*base_path\(/';
    // ② 「rtrim($base,'/') . '/' . ltrim($path,'/')」—— 应改用 join()
    $join = '/rtrim\([^();]*,\s*\'\/\'\)\s*\.\s*\'\/\'\s*\.\s*ltrim\(/';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = str_replace('\\', '/', str_replace($root . '/', '', $file->getPathname()));
        if ($rel === $anchor) {
            continue;     // 口径本体
        }

        $code = paths_code_without_comments($file->getPathname());

        if (preg_match($ternary, $code) === 1) {
            $offenders[] = "{$rel} → `? ... : base_path(...)`（应改用 Paths::fromBasePath()）";
        }
        if (preg_match($join, $code) === 1) {
            $offenders[] = "{$rel} → `rtrim(...,'/') . '/' . ltrim(...)`（应改用 Paths::join()）";
        }
    }

    expect($offenders)->toBe([], "以下文件自己实现了路径归一（应改用 Support\\Paths）：\n  " . implode("\n  ", $offenders));
});
