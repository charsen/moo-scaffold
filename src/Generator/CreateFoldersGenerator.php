<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Folders
 *
 * @author Charsen <780537@gmail.com>
 */
class CreateFoldersGenerator extends Generator
{

    public function start()
    {
        $database = [
            $this->utility->getDatabasePath('schema'),
            $this->utility->getDatabasePath('storage'),
        ];

        $api = [
            $this->utility->getApiPath('schema'),
            $this->utility->getApiPath('storage'),
        ];

        $folders = array_merge($database, $api);

        foreach ($folders as $folder)
        {
            $relative_path = str_replace(base_path(), '', $folder);
            if (!$this->filesystem->isDirectory($folder))
            {
                $this->filesystem->makeDirectory($folder, 0777, true, true);
                $this->command->info('+ ' . $relative_path);
            }
            else
            {
                $this->command->warn('- ' . $relative_path . ' (existed)');
            }
        }

        $this->buildGitIgnoreFile();
    }
    
    /**
     * 生成 脚手架的 .gitignore 文件
     */
    private function buildGitIgnoreFile()
    {
        $git_ignore_file = base_path() . '/scaffold/.gitignore';
        if (!$this->filesystem->isFile($git_ignore_file))
        {
            $gitignore_content = '.DS_Store' . PHP_EOL
                                 //. '/storage/database/*' . PHP_EOL
                                 //. '/storage/api/*' . PHP_EOL
                                 . '';
        
            $put = $this->filesystem->put(base_path() . '/scaffold/.gitignore', $gitignore_content);
            if ($put)
            {
                return $this->command->info('+ /scaffold/.gitignore');
            }
        
            return $this->command->error('add /scaffold/.gitignore file failed!');
        }
        else
        {
            $this->command->warn('- /scaffold/.gitignore (existed)');
        }
    }
}
