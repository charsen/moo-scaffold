<?php
namespace Charsen\Scaffold\Generator;

/**
 * Init Scaffold
 *
 * @author Charsen https://github.com/charsen
 */
class InitGenerator extends Generator
{
    
    /**
     * @param string $author
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start(string $author)
    {
        $this->updateEnvFile($author);
        
        $this->createFolder();
    }
    
    /**
     * 在 .evn 文件里添加 LARAVEL_SCAFFOLD_AUTHOR 信息
     *
     * @param string $author
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function updateEnvFile(string $author)
    {
        $file = base_path('.env');
        if ( ! $this->filesystem->isFile($file))
        {
            $this->command->warn('The .evn file is not found. (Can not save your name.)');
        }
        else
        {
            $env_txt = $this->filesystem->get($file);
            if (preg_match('/LARAVEL_SCAFFOLD_AUTHOR=.*/i', $env_txt))
            {
                $env_txt = preg_replace(
                    '/LARAVEL_SCAFFOLD_AUTHOR=.*/',
                    "LARAVEL_SCAFFOLD_AUTHOR={$author}",
                    $env_txt
                );
                $this->filesystem->put($file, $env_txt);
                $this->command->info(" .env updated LARAVEL_SCAFFOLD_AUTHOR={$author}");
            }
            else
            {
                $this->filesystem->append($file, "\nLARAVEL_SCAFFOLD_AUTHOR={$author}");
                $this->command->info("+ .env added LARAVEL_SCAFFOLD_AUTHOR={$author}");
            }
        }
    }
    
    /**
     * 创建目录
     */
    private function createFolder()
    {
        $database = [
            $this->utility->getDatabasePath('schema'),
            $this->utility->getDatabasePath('storage'),
        ];
    
        $api = [
            $this->utility->getApiPath('schema'),
        ];
    
        $folders = array_merge($database, $api);
    
        foreach ($folders as $folder)
        {
            $relative_path = str_replace(base_path(), '', $folder);
            if (!$this->filesystem->isDirectory($folder))
            {
                $this->filesystem->makeDirectory($folder, 0777, true, true);
                $this->command->info('+ .' . $relative_path);
            }
            else
            {
                $this->command->warn(' .' . $relative_path . ' (existed)');
            }
        }
    }
}
