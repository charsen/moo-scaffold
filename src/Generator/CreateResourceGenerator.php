<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-16 09:15
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-11 15:00
 * @Description: Create Resource
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;

class CreateResourceGenerator extends Generator
{
    protected array $built_resources = [];

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false, ?string $only_table = null): bool
    {
        $all = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');

        if (! isset($all[$schema_name])) {
            $this->console()->error("未找到 schema 文件 \"{$schema_name}\"。");

            return false;
        }

        // plan-53 出身:包 schema 的 Resource 落包 src/Http/Resources(平铺),写权硬线把闸
        $origin = null;
        foreach ($all[$schema_name] as $attr0) {
            $origin = $attr0['origin'] ?? null;

            break;
        }
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);

        foreach ($all[$schema_name] as $class => $attr) {
            // moo:free --table 过滤:只生成指定表 key 的代码,其它表跳过
            if ($only_table !== null && $attr['table_name'] !== $only_table) {
                continue;
            }

            $table_attr = $this->utility->getOneTable($attr['table_name']);

            foreach ($this->getResourceTargets($attr) as $target) {
                // 包平铺(无 module folder 段);host 按 folder 分层
                $resource_path = ! empty($target['flat']) ? rtrim($target['path'], '/') : $target['path'] . $attr['module']['folder'];
                $resource_file = $resource_path . "/{$class}Resource.php";
                $relative_file = $this->relDisplay($resource_file, $this->originCtx);

                if (isset($this->built_resources[$resource_file])) {
                    continue;
                }
                $this->built_resources[$resource_file] = true;

                // Model 目录检查，不存在则创建
                $this->checkDirectory($resource_path);

                // 检查是否存在，存在则不更新
                if ($this->filesystem->isFile($resource_file) && ! $force) {
                    $this->console()->exists($relative_file, 'Resource 已存在');

                    $this->console()->newLine();

                    continue;
                }

                // 生成 resource & collection
                $this->buildResource($resource_path, $class, $attr, $table_attr, $target);
                // $this->buildResourceCollection($resource_path, $class, $attr, $table_attr);
            }
        }

        return true;
    }

    /**
     * 创建 resource 文件
     */
    private function buildResource(string $resource_path, string $class, array $schema, array $table_attr, array $target): void
    {
        // 文件处理
        $resource_file = $resource_path . "/{$class}Resource.php";
        $relative_file = $this->relDisplay($resource_file, $this->originCtx);
        $file_exists   = $this->filesystem->isFile($resource_file);

        $meta = [
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i'),
            'namespace'   => ! empty($target['flat']) ? rtrim($target['namespace'], '\\') : $target['namespace'] . "{$schema['module']['folder']}",
            'class'       => $class,
            'class_name'  => $table_attr['name'] . ' 资源',
            'fields_code' => $this->getFieldCode($table_attr),
        ];

        // 生成 resource 文件
        $content = $this->buildStub($meta, $this->getStub('resource'));
        $this->filesystem->put($resource_file, $content);
        if ($file_exists) {
            $this->console()->overwritten($relative_file);
        } else {
            $this->console()->created($relative_file);
        }
    }

    /**
     * 获取当前模型要输出的 resource 目标目录
     */
    private function getResourceTargets(array $schema): array
    {
        // plan-53:包 schema 单一目标 — 包 src/Http/Resources(平铺,app 固定 admin)
        if ($this->originCtx !== null) {
            $path = $this->originCtx->pathFor('resource');

            return [[
                'app'           => 'admin',
                'path'          => $path,
                'relative_path' => $this->relDisplay($path, $this->originCtx),
                'namespace'     => $this->originCtx->namespaceFor('resource') . '\\',
                'flat'          => true,
            ]];
        }

        $targets = [];
        $apps    = $schema['resource'] ?? [];

        foreach ($apps as $app) {
            $config = $this->utility->getConfig("controller.{$app}");
            if (empty($config)) {
                $this->console()->error("未配置 Resource app \"{$app}\"。");

                continue;
            }

            $relativePath = $this->utility->getAppResourcePath($app, true);
            $targets[]    = [
                'app'           => $app,
                'path'          => $this->utility->getAppResourcePath($app),
                'relative_path' => $relativePath,
                'namespace'     => $this->utility->formatNameSpace($relativePath),
                'flat'          => false,
            ];
        }

        return $targets;
    }

    /**
     * 创建 resource collection 文件
     */
    // private function buildResourceCollection(string $resource_path, string $class, array $schema, array $table_attr): void
    // {
    //     // 文件处理
    //     $collection_file = $resource_path . "/{$class}Collection.php";
    //     $relative_file   = $this->model_relative_path . $schema['module']['folder'] . "/{$class}Collection.php";

    //     $meta = [
    //         'author'      => $this->utility->getConfig('author'),
    //         'date'        => date('Y-m-d H:i'),
    //         'namespace'   => $this->base_namespace . "{$schema['module']['folder']}",
    //         'class'       => $class,
    //         'class_name'  => $table_attr['name'] . ' 资源集合',
    //         'fields_code' => $this->getFieldCode($table_attr['fields'], $table_attr['enums']),
    //     ];

    //     // 生成 resource collection 文件
    //     $content = $this->buildStub($meta, $this->getStub('resource-collection'));
    //     $this->filesystem->put($collection_file, $content);
    //     $this->command->info('+ ' . $relative_file);
    // }

    /**
     * 生成 class property 代码s
     */
    private function getFieldCode(array $table_attr): string
    {
        $fields = $table_attr['fields'];
        $enums  = $table_attr['enums'];
        $index  = $table_attr['index'];
        $code   = [];

        foreach ($fields as $field_name => $attr) {
            if (Str::startsWith($field_name, '_') or str_contains($field_name, 'password')) {
                continue;
            }

            if ($field_name === 'deleted_at') {
                $code[] = $this->getTabs(3) . "'deleted_at' => \$this->whenTrashed(\$this->deleted_at),";

                continue;
            }

            if (in_array($attr['type'], ['date', 'datetime', 'timestamp']) && $field_name !== 'updated_at') {
                $code[] = $this->getTabs(3) . "'{$field_name}' => \$this->whenDate('{$field_name}'),";

                continue;
            }

            // 暂时，定一个规则，不是索引字段，都加上 whenHas()
            if (isset($index[$field_name])) {
                $code[] = $this->getTabs(3) . "'{$field_name}' => \$this->{$field_name},";
            } else {
                $code[] = $this->getTabs(3) . "'{$field_name}' => \$this->whenHas('{$field_name}'),";
            }

            // append 字段
            if (isset($enums[$field_name])) {
                $code[] = $this->getTabs(3) . "'{$field_name}_txt' => \$this->whenAppended('{$field_name}_txt'),";
            }
        }

        // options 的处理
        $code[] = $this->getTabs(3) . "'options' => \$this->whenAppended('options'),";

        // 去掉第一行的缩进，stub 文件中缩进了
        $code[0] = trim($code[0]);

        return implode(PHP_EOL, $code);
    }
}
