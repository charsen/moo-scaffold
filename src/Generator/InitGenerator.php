<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:02
 * @Description: Init Scaffold
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Mooeen\Scaffold\Support\Paths;

class InitGenerator extends Generator
{
    /**
     * @throws FileNotFoundException
     */
    public function start(string $author): bool
    {
        $this->createFolder();

        $this->updateEnvFile($author);

        $this->utility->addGitIgnore($this->console());

        return true;
    }

    /**
     * 在 .evn 文件里添加 SCAFFOLD_AUTHOR 信息
     *
     * @throws FileNotFoundException
     */
    private function updateEnvFile(string $author): void
    {
        $file = base_path('.env');
        if (! $this->filesystem->isFile($file)) {
            $this->console()->error('未找到 `.env` 文件，`SCAFFOLD_AUTHOR` 无法写入。');
        } else {
            $env_txt = $this->filesystem->get($file);
            if (preg_match('/SCAFFOLD_AUTHOR=.*/i', $env_txt)) {
                $env_txt = preg_replace(
                    '/SCAFFOLD_AUTHOR=.*/',
                    "SCAFFOLD_AUTHOR=\"{$author}\"",
                    $env_txt
                );
                if (! $this->putOrReport($file, '.env', $env_txt)) {
                    return;
                }

                $this->console()->updated('.env', 'Updated `SCAFFOLD_AUTHOR`');
            } else {
                if ($this->filesystem->append($file, "\nSCAFFOLD_AUTHOR=\"{$author}\"") === false) {
                    $this->console()->failed('.env', '写入失败，`SCAFFOLD_AUTHOR` 未添加');

                    return;
                }

                $this->console()->added('.env', 'Added `SCAFFOLD_AUTHOR`');
            }
        }
    }

    /**
     * 创建目录
     */
    private function createFolder(): void
    {
        $folders = [
            Paths::database('schema'),
            storage_path('scaffold/'),
            // Paths::api('schema'),
        ];

        foreach ($folders as $folder) {
            $relative_path = str_replace(base_path(), '', $folder);
            $this->checkDirectory($folder);
            $this->console()->ready('.' . $relative_path, 'Directory ready');
        }
    }
}
