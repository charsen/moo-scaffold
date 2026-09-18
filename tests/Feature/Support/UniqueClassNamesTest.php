<?php declare(strict_types=1);

use Mooeen\Scaffold\Forms\FieldTypes;
use Mooeen\Scaffold\Support\ColumnTypeGroups;

/**
 * 类命名锚点：同一短名不得跨越两个顶层目录。
 *
 * 为什么钉这个：本仓 2026-09-18 之前同时存在 `Support\FieldTypes` 与 `Forms\FieldTypes`，
 * 两者管的事**完全不相交**（前者是 MySQL 列类型分组，后者是表单字段契约），但
 * `use Mooeen\Scaffold\Support\FieldTypes;` 与 `use Mooeen\Scaffold\Forms\FieldTypes;`
 * 光看短名分不出来 —— 读代码时要在两个文件间来回跳才知道是哪一套。改名本身好做，
 * 难的是**不让它复发**：这类撞名不会报任何错，只会持续消耗读代码的人。
 *
 * 规则：**允许同层内的重名**（`Http\Requests\{Api,Route}\IndexRequest` 这种靠 feature
 * 子命名空间区分，是 Laravel 惯用法），**禁止跨顶层目录的重名**（`Forms\X` 与 `Support\X`
 * 这种，短名再也给不出任何定位信息）。已登记的例外写在下面的常量里，带原因。
 *
 * 判定口径：顶层目录取**文件路径**的 `src/` 下一段，而不是命名空间末段 —— 后者对
 * `Http\Requests\Api` / `Http\Requests\Route` 会算出两个不同的值，把同层重名误判成跨层。
 */

/** 短名 => 例外原因。空原因视为未登记（仍报违规）。 */
const NAMING_LAYER_EXCEPTIONS = [
    // 两侧服务**不同生态**，不是可收口的历史包袱（2026-09-18 第 9 项逐处核验）：
    //   Foundation\Controller        —— `scaffold.class.controller` 生成给**宿主**的控制器基类
    //                                    （Adder / Generator 把它写进宿主产物：`use …Foundation\Controller` + `extends Controller`）；
    //                                    `src/` 内**零继承者**，只有测试拿它当 ACL harness。
    //   Http\Controllers\Controller  —— scaffold 自己 13 个 UI 控制器的基类（$utility / view() / currentOperator()）。
    'Controller' => 'Foundation\Controller（生成给宿主的控制器基类，src/ 零继承者）/ Http\Controllers\Controller（scaffold UI 基类，13 个控制器）',
];

/**
 * 扫 `src/` 下所有声明的类/接口/trait/enum。
 *
 * 用 `token_get_all` 而不是正则：正则会把**注释掉的**类和字符串里的 `class X` 当成声明
 * （本仓在 ReadonlyModeTest 上踩过同类坑）。同时跳过匿名类（`new class extends ...`）
 * 与 `Foo::class`（也是 T_CLASS，但前一个非空白 token 是 T_DOUBLE_COLON）。
 *
 * @return array<string, list<string>> 短名 => list<"顶层目录/文件名  (FQCN)">
 */
function naming_scan_declarations(string $root): array
{
    $declared = [];
    $files    = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = str_replace('\\', '/', str_replace($root . '/', '', $file->getPathname()));
        // src/Support/Concerns/AtomicFileWrite.php → Concerns/AtomicFileWrite.php
        $where  = substr($rel, strlen('src/'));
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count  = count($tokens);
        $ns     = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $ns .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }

                continue;
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $prev = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $prev = $tokens[$j];
                break;
            }
            if (is_array($prev) && in_array($prev[0], [T_NEW, T_DOUBLE_COLON], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $declared[$tokens[$j][1]][] = "{$where}  ({$ns}\\{$tokens[$j][1]})";
                    break;
                }
            }
        }
    }

    ksort($declared);

    return $declared;
}

it('同名类锚点：同一短名不得跨越两个顶层目录（防 FieldTypes 式撞名复发）', function () {
    $root      = dirname(__DIR__, 3);
    $offenders = [];

    foreach (naming_scan_declarations($root) as $short => $places) {
        // 顶层目录 = 相对 src/ 的第一段（不是命名空间末段，见文件头说明）
        $layers = array_unique(array_map(static fn (string $p): string => explode('/', $p)[0], $places));

        if (count($layers) < 2) {
            continue;     // 同层内重名靠 feature 子命名空间区分，允许
        }

        $reason = NAMING_LAYER_EXCEPTIONS[$short] ?? '';
        if ($reason !== '') {
            continue;     // 已登记的例外
        }

        $offenders[] = "{$short}:" . PHP_EOL . '    ' . implode(PHP_EOL . '    ', $places);
    }

    expect($offenders)->toBe([], "以下短名跨越了两个顶层目录（读代码时短名给不出定位信息）：\n  "
        . implode(PHP_EOL . '  ', $offenders)
        . PHP_EOL . '  → 给其中没有仓外消费者的那个改一个说得出职责的名字；'
        . PHP_EOL . '    若确实该并存，在 NAMING_LAYER_EXCEPTIONS 里登记并写明原因。');
});

it('例外登记必须有原因（防止有人图省事直接塞个空串进白名单）', function () {
    foreach (NAMING_LAYER_EXCEPTIONS as $short => $reason) {
        expect(trim($reason))->not->toBe('', "例外 `{$short}` 没写原因");
    }
});

it('本次改名的回归锁：Support\\ColumnTypeGroups 就位，Forms\\FieldTypes 未被改动', function () {
    $root = dirname(__DIR__, 3);

    expect(class_exists(ColumnTypeGroups::class))->toBeTrue()
        ->and((new ReflectionClass(ColumnTypeGroups::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(ColumnTypeGroups::class))->getNamespaceName())->toBe('Mooeen\Scaffold\Support')
        // 旧名不得复活（否则短名撞名又回来了）
        ->and(file_exists($root . '/src/Support/FieldTypes.php'))->toBeFalse();

    // 跨仓扩展契约必须留在原处：下游有 is_a(..., FieldTypes::class) 校验与直接 extends
    expect(class_exists(FieldTypes::class))->toBeTrue()
        ->and((new ReflectionClass(FieldTypes::class))->getNamespaceName())->toBe('Mooeen\Scaffold\Forms');
});
