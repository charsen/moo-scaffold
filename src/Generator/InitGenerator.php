<?php

namespace Mooeen\Scaffold\Generator;

/**
 * Init Scaffold
 *
 * @author Charsen https://github.com/charsen
 */
class InitGenerator extends Generator
{
    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start(string $author): bool
    {
        $this->createFolder();

        $this->updateEnvFile($author);

        $this->utility->addGitIgnore($this->command);

        return true;
    }

    /**
     * 在 .evn 文件里添加 SCAFFOLD_AUTHOR 信息
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function updateEnvFile(string $author): void
    {
        $file = base_path('.env');
        if (! $this->filesystem->isFile($file)) {
            $this->command->error('The .evn file is not found. (Can not save your name.)');
        } else {
            $env_txt = $this->filesystem->get($file);
            if (preg_match('/SCAFFOLD_AUTHOR=.*/i', $env_txt)) {
                $env_txt = preg_replace(
                    '/SCAFFOLD_AUTHOR=.*/',
                    "SCAFFOLD_AUTHOR=\"{$author}\"",
                    $env_txt
                );
                $this->filesystem->put($file, $env_txt);
                $this->command->info(" .env updated SCAFFOLD_AUTHOR=\"{$author}\"");
            } else {
                $this->filesystem->append($file, "\nSCAFFOLD_AUTHOR=\"{$author}\"");
                $this->command->info("+ .env added SCAFFOLD_AUTHOR=\"{$author}\"");
            }
        }
    }

    /**
     * 创建目录
     */
    private function createFolder(): void
    {
        $folders = [
            $this->utility->getDatabasePath('schema'),
            storage_path('scaffold/'),
            // $this->utility->getApiPath('schema'),
        ];

        foreach ($folders as $folder) {
            $relative_path = str_replace(base_path(), '', $folder);
            $this->checkDirectory($folder);
            $this->command->info('+ .' . $relative_path);
        }
    }
}
