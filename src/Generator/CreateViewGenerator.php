<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-09-08 17:37
 * @Description: Create Frontend View
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Utility;

class CreateViewGenerator extends Generator
{
    protected string $view_path;

    protected string $view_relative_path;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, string $controller, bool $force = false): bool
    {
        $this->view_path          = $this->utility->getConfig('frontend.views');
        $this->view_relative_path = str_replace([base_path('../'), '../'], ['', '/'], $this->view_path);

        $all  = $this->utility->getControllers(false);
        $attr = $all[$schema_name][$controller];

        // 删除字符串尾部的 Controller 字符
        $attr['class'] = Utility::stripControllerSuffix($controller);

        $module             = Str::snake($attr['module']['folder'], '-');
        $entity             = Str::snake($attr['class'], '-');
        $view_path          = $this->view_path . $module . '/' . $entity;
        $view_relative_path = $this->view_relative_path . $module . '/' . $entity;

        // View 目录检查，不存在则创建
        $this->checkDirectory($view_path);

        // 如果视图文件已存在，则跳过
        // 仅用 index.vue 作为判断依据，不管 trashed, show.vue 是否存在
        $view_file     = $view_path . '/index.vue';
        $relative_file = $view_relative_path . '/index.vue';

        if ($this->filesystem->isFile($view_file) && ! $force) {
            $this->console()->exists($relative_file, '前端视图已存在');
        } else {
            $acl_key = Str::snake($schema_name . $attr['class'], '-');

            $this->buildView('view-index', 'index.vue', $view_path, $view_relative_path, $attr, $acl_key);
            $this->buildView('view-trashed', 'trashed.vue', $view_path, $view_relative_path, $attr, $acl_key);
            $this->buildView('view-show', 'show.vue', $view_path, $view_relative_path, $attr, $acl_key);
        }

        return true;
    }

    private function buildView(
        string $stub,
        string $filename,
        string $view_path,
        string $view_relative_path,
        array $schema,
        string $acl_key,
    ): void {
        $view_file     = $view_path . "/{$filename}";
        $relative_file = $view_relative_path . "/{$filename}";
        $file_exists   = $this->filesystem->isFile($view_file);

        $meta = [
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i'),
            'model_class' => $schema['model_class'],
            'acl_key'     => $acl_key,
        ];

        $this->filesystem->put($view_file, $this->buildStub($meta, $this->getFrontendStub($stub)));

        if ($file_exists) {
            $this->console()->overwritten($relative_file);
        } else {
            $this->console()->created($relative_file);
        }
    }
}
