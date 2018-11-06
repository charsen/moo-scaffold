<?php
namespace Charsen\Scaffold\Generator;

use InvalidArgumentException;

/**
 * Create Model
 *
 * @author Charsen https://github.com/charsen
 */
class CreateControllerGenerator extends Generator
{
    
    protected $controller_path;
    protected $controller_relative_path;
    protected $repository_folder;
    
    /**
     * @param      $schema_name
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($schema_name, $force = false)
    {
        $this->controller_path          = $this->utility->getControllerPath();
        $this->controller_relative_path = $this->utility->getControllerPath(true);
        $this->repository_folder        = $this->utility->getRepositoryFolder();
        
        // 从 storage 里获取控制器数据，在修改了 schema 后忘了执行 scaffold:fresh 的话会不准确！！
        $all = $this->utility->getControllers(false);
        
        if (!isset($all[$schema_name]))
        {
            throw new InvalidArgumentException(sprintf('Schema File "%s" could not be found.', $schema_name));
        }
        
        //dump($all[$schema_name]);
        foreach ($all[$schema_name] as $class => $attr)
        {
            $controller_file          = $this->controller_path . "{$class}Controller.php";
            $controller_relative_file = $this->controller_relative_path . "{$class}Controller.php";
            if ($this->filesystem->isFile($controller_file) && !$force)
            {
                $this->command->error('x Controller is existed (' . $controller_relative_file . ')');
                continue;
            }
            
            // 目录及 namespace 处理
            $namespace      = $this->dealNameSpaceAndPath($this->controller_path, 'app/Http/Controllers', $class);
            
            $use_repository = 'use ' . ucfirst($this->repository_folder) . $attr['repository_class'] . 'Repository;';
            $temp           = explode('/', $attr['repository_class']);
            $meta           = [
                'author'            => $this->utility->getConfig('author'),
                'date'              => date('Y-m-d H:i:s'),
                'controller_class'  => $class,
                'namespace'         => ucfirst($namespace),
                'use_repository'    => str_replace('/', '\\', $use_repository),
                'repository_class'  => end($temp) . 'Repository',
            ];
    
            $this->filesystem->put($controller_file, $this->compileStub($meta));
            $this->command->info('+ ' . $controller_relative_file);
        }
    }
    
    /**
     * 编译模板
     *
     * @param $meta
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileStub(array $meta)
    {
        return $this->buildStub($meta, $this->getStub('controller'));
    }
}
