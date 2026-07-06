<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Description: Create Feature Test —— 给生成的控制器吐路由契约冒烟测（Pest，B-lean）
 *
 * 测「codegen 把路由插对了 + 控制器能加载」，按控制器 FQCN 反查路由（不依赖资源 slug 命名约定），
 * 不碰 DB / auth / factory。生成一次（除非 -f）—— 用户可往生成的文件里继续补真业务断言。
 *
 * 注：一个控制器可挂多个 app（$attr['app'] 是数组，如 ['admin','api']）—— 每个 app 命名空间 / 路由
 * 各异，逐 app 各落一份测到 tests/Feature/{App}/{module}/，跟 CreateControllerGenerator 逐 app 生成对齐。
 */

namespace Mooeen\Scaffold\Generator;

class CreateTestGenerator extends Generator
{
    public function start(string $schema_name, string $controller, bool $force = false): bool
    {
        $all    = $this->utility->getControllers(false);
        $attr   = $all[$schema_name][$controller];
        $module = $attr['module']['folder'];

        // 落点 base：tests.path（绝对 → 原样用；相对 → base_path() 前缀）。
        $configured = (string) $this->utility->getConfig('tests.path');
        $test_base  = str_starts_with($configured, '/') ? $configured : base_path($configured);

        foreach ((array) $attr['app'] as $app_raw) {
            $app_folder = strtolower((string) $app_raw);
            $config_key = 'controller.' . $app_folder . '.path';

            // 该 app 没配 controller.path → 推不出命名空间，跳过（防脏 FQCN）。
            if (empty($this->utility->getConfig($config_key))) {
                continue;
            }

            // FQCN：namespace_pre + module + controller，跟 CreateControllerGenerator 同源。
            $namespace_pre = $this->utility->formatNameSpace($this->utility->getControllerPath($config_key, true));
            $fqcn          = $namespace_pre . $module . '\\' . $controller;

            $app_dir   = ucfirst($app_folder);
            $test_dir  = rtrim($test_base, '/') . '/' . $app_dir . '/' . $module;
            $test_file = $test_dir . '/' . $controller . 'Test.php';
            $relative  = 'tests/Feature/' . $app_dir . '/' . $module . '/' . $controller . 'Test.php';

            $this->checkDirectory($test_dir);

            if ($this->filesystem->isFile($test_file) && ! $force) {
                $this->console()->exists($relative, 'Feature 测试已存在');

                continue;
            }

            $existed = $this->filesystem->isFile($test_file);

            $meta = [
                'author'          => (string) $this->utility->getConfig('author'),
                'date'            => date('Y-m-d H:i'),
                'controller'      => $controller,
                'controller_fqcn' => $fqcn,
            ];
            $this->filesystem->put($test_file, $this->buildStub($meta, $this->getStub('test')));

            $existed ? $this->console()->overwritten($relative) : $this->console()->created($relative);
        }

        return true;
    }

    /**
     * 该 schema 下会落测的相对目录（去重）—— 给命令打「择机跑」提示用，
     * 只算 app + module（不推 FQCN），并套跟 start() 同一条 controller.path 空配跳过守护。
     *
     * @return list<string> 形如 ['tests/Feature/Admin/Market']
     */
    public function testDirs(string $schema_name): array
    {
        $all  = $this->utility->getControllers(false)[$schema_name] ?? [];
        $dirs = [];

        foreach ($all as $attr) {
            $module = $attr['module']['folder'];

            foreach ((array) $attr['app'] as $app_raw) {
                $app_folder = strtolower((string) $app_raw);

                if (empty($this->utility->getConfig('controller.' . $app_folder . '.path'))) {
                    continue;
                }

                $dir        = 'tests/Feature/' . ucfirst($app_folder) . '/' . $module;
                $dirs[$dir] = $dir;
            }
        }

        return array_values($dirs);
    }
}
