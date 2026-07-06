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
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Support\Concerns\InteractsWithConsoleUi;
use Mooeen\Scaffold\Support\Concerns\ResolvesOriginContext;
use Mooeen\Scaffold\Utility;

class Adder
{
    use InteractsWithConsoleUi;
    use ResolvesOriginContext;

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

    /**
     * 检查 文件夹是否存在，不存在则创建
     */
    protected function checkDirectory(string $path): void
    {
        if (! $this->filesystem->isDirectory($path)) {
            $this->filesystem->makeDirectory($path, 0777, true, true);
        }
    }

    /**
     * 获取 tabs 缩进
     */
    protected function getTabs(float $size = 1): string
    {
        return str_repeat(' ', (int) ($size * 4)); // (int) 必须:strict_types 下 str_repeat 第二参收 float 抛 TypeError(对齐 Generator::getTabs)
    }

    /**
     * Build file replacing metas in template.
     */
    protected function buildStub(array $metas, string $template): string
    {
        foreach ($metas as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    /**
     * Get the Stub Path.
     */
    protected function getStubPath(): string
    {
        return __DIR__ . '/../../stubs/';
    }

    /**
     * 获取模板
     *
     *
     *
     * @throws FileNotFoundException
     */
    protected function getStub($file_name): string
    {
        return $this->filesystem->get($this->getStubPath() . "{$file_name}.stub");
    }

    protected function getFirstUseLine($codes): int
    {
        $lines_count = count($codes) - 1;
        $use_line    = -1;
        $start_line  = 0;
        do {
            if (preg_match('/^\s*use\s+/', $codes[$start_line])) {
                $use_line = $start_line;
                break; // 找到第一个就退出
            }
        } while ($start_line++ < $lines_count);

        return $use_line;
    }

    protected function getEndLine($codes): int
    {
        $end_line   = -1;
        $start_line = count($codes) - 1;
        do {
            if (trim($codes[$start_line]) === '}') {
                $end_line = $start_line;
                break;
            }
        } while ($start_line-- >= 0);

        return $end_line;
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
        $lines_count = count($codes) - 1;
        $has         = false;
        $start_line  = 0;
        $pattern     = '/use\s+[\w\\\\]+\\\\' . preg_quote($class_name, '/') . ';/';

        do {
            if (preg_match($pattern, $codes[$start_line])) {
                $has = true;
                break; // 找到第一个就退出
            }
        } while ($start_line++ < $lines_count);

        return $has;
    }

    protected function hasFunction($codes, $action_name = 'index'): bool
    {
        $lines_count = count($codes) - 1;
        $has         = false;
        $start_line  = 0;
        $pattern     = '/(public|private|protected)\s+function\s+' . preg_quote($action_name, '/') . '\s*\(/';

        do {
            if (preg_match($pattern, $codes[$start_line])) {
                $has = true;
                break; // 找到第一个就退出
            }
        } while ($start_line++ < $lines_count);

        return $has;
    }

    protected function replaceLine($new, &$codes, $line, $place = 'front'): void
    {
        $code = ($place === 'front')
            ? $new . PHP_EOL . $codes[$line]
            : $codes[$line] . $new . PHP_EOL;

        $codes[$line] = $code;
    }
}
