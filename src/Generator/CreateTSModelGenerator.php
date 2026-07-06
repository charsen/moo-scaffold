<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-16 09:15
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 11:38
 * @Description: Create TypeScript Model
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;

use function in_array;

class CreateTSModelGenerator extends Generator
{
    protected string $model_path;

    protected string $model_relative_path;

    protected string $base_namespace;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false, ?string $only_table = null): bool
    {
        $this->model_path          = $this->utility->getConfig('frontend.models');
        $this->model_relative_path = str_replace([base_path('../'), '../'], ['', '/'], $this->model_path);

        $all = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');

        if (! isset($all[$schema_name])) {
            $this->console()->error("未找到 schema 文件 \"{$schema_name}\"。");

            return false;
        }

        foreach ($all[$schema_name] as $class => $attr) {
            // moo:model --table 过滤:只生成指定表 key 的代码,其它表跳过
            if ($only_table !== null && $attr['table_name'] !== $only_table) {
                continue;
            }

            $model_path    = $this->model_path . $attr['module']['folder'];
            $model_file    = $model_path . "/{$class}.ts";
            $relative_file = $this->model_relative_path . $attr['module']['folder'] . "/{$class}.ts";

            // Model 目录检查，不存在则创建
            $this->checkDirectory($model_path);

            // 检查是否存在，存在则不更新
            if ($this->filesystem->isFile($model_file) && ! $force) {
                $this->console()->exists($relative_file, 'TypeScript 模型已存在');

                $this->console()->newLine();

                continue;
            }

            $table_attr = $this->utility->getOneTable($attr['table_name']);

            // 生成 model
            $this->buildModel($model_path, $class, $attr, $table_attr);
        }

        return true;
    }

    /**
     * 创建 model 文件
     */
    private function buildModel(string $model_path, string $class, array $schema, array $table_attr): void
    {
        // 文件处理
        $model_file    = $model_path . "/{$class}.ts";
        $relative_file = $this->model_relative_path . $schema['module']['folder'] . "/{$class}.ts";
        $file_exists   = $this->filesystem->isFile($model_file);

        $meta = [
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i'),
            'class'       => $class,
            'class_name'  => $table_attr['name'] . '模型',
            'fields_code' => $this->getFieldCode($table_attr['fields']),
        ];

        // 生成 model 文件
        $content = $this->buildStub($meta, $this->getFrontendStub('model'));
        $this->filesystem->put($model_file, $content);
        if ($file_exists) {
            $this->console()->overwritten($relative_file);
        } else {
            $this->console()->created($relative_file);
        }
    }

    /**
     * 生成 class property 代码s
     */
    public function getFieldCode(array $fields): string
    {
        $code = [];

        foreach ($fields as $field_name => $attr) {
            if (in_array($attr['type'], ['bigint'])) {
                $type = 'bigint | string';
            } elseif (in_array($attr['type'], ['tinyint', 'smallint', 'mediumint', 'int'])) {
                $type = 'number';
            } elseif (in_array($attr['type'], ['bool', 'boolean'])) {
                $type = 'boolean';
            }
            // elseif ($attr['type'] === 'array') {
            //     $type = 'Array<string>';
            // } elseif ($attr['type'] === 'json') {
            //     $type = 'JSON';
            // }
            else {
                $type = 'string';
            }
            // 同 CreateModelGenerator:yaml 字段未声明 name 时用 field_name 兜底,不阻塞
            $code[] = $this->getTabs(0.5) . '// ' . ($attr['name'] ?? $field_name);
            $code[] = $this->getTabs(0.5) . "{$field_name}?: {$type}";
        }

        $code[] = $this->getTabs(0.5) . '// 操作数组';
        $code[] = $this->getTabs(0.5) . 'options?: Option[]';

        return implode(PHP_EOL, $code);
    }
}
