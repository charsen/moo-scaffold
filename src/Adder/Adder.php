<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-27 17:02
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-30 16:44
 * @Description: Adder
 */

namespace Mooeen\Scaffold\Adder;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Support\Concerns\InteractsWithConsoleUi;
use Mooeen\Scaffold\Support\Concerns\ResolvesOriginContext;
use Mooeen\Scaffold\Support\Concerns\SharedCodegenHelpers;
use Mooeen\Scaffold\Utility;

class Adder
{
    use InteractsWithConsoleUi;
    use ResolvesOriginContext;

    // 缩进 / 目录 / stub 读取 / escape 三件套：与 Generator 共用（2026-09-11 从两边各抄一份收口）
    use SharedCodegenHelpers;

    /**
     * @var mixed
     */
    protected Filesystem $filesystem;

    /**
     * @var mixed
     */
    protected Command|Factory $command;

    /**
     * @var mixed
     */
    protected Utility $utility;

    /**
     * Create a new command instance.
     */
    public function __construct(Command|Factory $command, Filesystem $filesystem, Utility $utility)
    {
        $this->command    = $command;
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    protected function getConsoleTarget(): Command|Factory
    {
        return $this->command;
    }

    // -------------------------------------------------------------------------
    // 输入守卫：Adder 是唯一直接吃人敲输入的生成入口（moo:adder 的 action /
    // 控制器名 / 路由串都由 prompt 得来），这些值会被原样拼进生成的 PHP。
    // 2026-09-11 加：原先零校验，含 `;` `}` 引号 换行 或 `../` 都能产出语法错文件 /
    // 注入 / 目录穿越。
    // -------------------------------------------------------------------------

    /**
     * 非法输入的统一出口：打一行 failed 并返回 false，便于 `return $this->invalidInput(...)`。
     */
    protected function invalidInput(string $subject, string $message): bool
    {
        $this->console()->failed($subject === '' ? '(空)' : $subject, $message);

        return false;
    }

    /**
     * PHP 标识符：字母或下划线开头，其后字母 / 数字 / 下划线。
     * 用于 action 方法名、控制器类名、路由方法名（`Route::get`）。
     */
    protected function isPhpIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * 以 `\` 分隔的全限定类名（App\Admin\Controllers\MemoController）。
     */
    protected function isPhpQualifiedName(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (explode('\\', $value) as $segment) {
            if (! $this->isPhpIdentifier($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 控制器定位串：`Name` 或 `Folder/Name` 形式（AdderCommand 的选择列表就是这个形态）。
     * 逐段校验既挡住了 `..` 目录穿越，也挡住带引号 / 空格 / 分号的注入。
     */
    protected function isControllerPath(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (explode('/', $value) as $segment) {
            if (! $this->isPhpIdentifier($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 读取待追加的 PHP 源文件为行数组；null 表示不可读。
     *
     * 2026-09-11：原先 ControllerAdder 直接 `file($file_path)`，目标文件不存在时拿到 false，
     * 随后 `count(false)` 在 PHP 8 抛 TypeError，命令崩在「读文件」这一步而不是给出可读报错。
     * 调用方拿到 null 必须中止，不得继续走生成流程。
     */
    protected function readSourceLines(string $file_path): ?array
    {
        if (! $this->filesystem->isFile($file_path)) {
            return null;
        }

        $lines = file($file_path);

        return $lines === false ? null : $lines;
    }

    /**
     * 第一条 `use` 语句所在行；找不到返回 -1。
     *
     * 2026-09-11：原实现以 `count($codes) - 1` 起算并 do/while 读 `$codes[0]`，空数组会索引到
     * 不存在的位置（$codes 为 false 时 count() 直接 TypeError）。改 foreach 后无越界。
     * 返回的 -1 是「锚点缺失」信号 —— 调用方必须中止，而不是把新内容写进负下标。
     */
    protected function getFirstUseLine($codes): int
    {
        foreach (is_array($codes) ? $codes : [] as $line => $code) {
            if (preg_match('/^\s*use\s+/', (string) $code)) {
                return (int) $line;
            }
        }

        return -1;
    }

    /**
     * 类闭合 `}` 所在行；找不到返回 -1。
     *
     * 2026-09-11：原 `while ($start_line-- >= 0)` 在 start_line 归 0 后仍会再走一轮循环体，
     * 读到 `$codes[-1]`（Undefined array key -1）并把 -1 返给调用方。改为先判条件再进循环体。
     */
    protected function getEndLine($codes): int
    {
        $codes = is_array($codes) ? $codes : [];

        for ($line = count($codes) - 1; $line >= 0; $line--) {
            if (trim((string) $codes[$line]) === '}') {
                return $line;
            }
        }

        return -1;
    }

    protected function checkGlobalResource($resource_name): bool|string
    {
        $path  = base_path('/') . config('scaffold.resource.path');
        $files = array_filter($this->filesystem->allFiles($path), function ($file) {
            return Str::endsWith($file, 'Resource.php');
        });

        $exist = null;
        foreach ($files as $file) {
            $name = Str::replaceEnd('.php', '', $file->getFilename());
            if ($name === $resource_name) {
                $exist = $file;
                break;
            }
        }

        if ($exist === null) {
            return false;
        }

        $file_path = Str::replaceEnd('.php', '', $exist->getPathname());
        $class     = str_replace([$path, '/'], ['', '\\'], $file_path);
        // resource.path 带尾 `/` → formatNameSpace 产出尾部带 `\` 的 namespace,再拼 `\\{class}` 得到
        // 双反斜杠 `Resources\\Foo`(空命名空间段)→ 生成的 controller use 语句 PHP 语法错(2026-06-09 修)。
        $namespace = rtrim($this->utility->formatNameSpace('./' . config('scaffold.resource.path')), '\\');

        return "use {$namespace}\\{$class};";
    }

    protected function hasUseClass($codes, $class_name = 'BaseResource'): bool
    {
        $pattern = '/use\s+[\w\\\\]+\\\\' . preg_quote($class_name, '/') . ';/';

        // 2026-09-11：同 getFirstUseLine，原 do/while 以 count()-1 起算，空数组 / false 会越界或 TypeError。
        foreach (is_array($codes) ? $codes : [] as $code) {
            if (preg_match($pattern, (string) $code)) {
                return true;
            }
        }

        return false;
    }

    protected function hasFunction($codes, $action_name = 'index'): bool
    {
        $pattern = '/(public|private|protected)\s+function\s+' . preg_quote($action_name, '/') . '\s*\(/';

        // 2026-09-11：同上 —— 该方法是 ControllerAdder::start 读文件后的第一站，
        // 原实现在 $file_codes 为 false 时先于守卫炸成 TypeError。
        foreach (is_array($codes) ? $codes : [] as $code) {
            if (preg_match($pattern, (string) $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 在指定行前/后插入内容，返回是否真的写入。
     *
     * 2026-09-11：调用方若把 -1（锚点缺失）直接传进来，原实现会写 `$codes[-1]` ——
     * 负下标在数组里是「新增键」，后续 `implode` 遍历键 0..n-1 时根本不带它，
     * 于是文件看似生成成功、内容却静默消失。这里显式拒绝并让调用方感知。
     */
    protected function replaceLine($new, &$codes, $line, $place = 'front'): bool
    {
        if (! is_int($line) || $line < 0 || ! is_array($codes) || ! array_key_exists($line, $codes)) {
            return false;
        }

        $code = ($place === 'front')
            ? $new . PHP_EOL . $codes[$line]
            : $codes[$line] . $new . PHP_EOL;

        $codes[$line] = $code;

        return true;
    }
}
