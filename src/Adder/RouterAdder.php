<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-27 17:12
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-31 10:14
 * @Description: 控制器增量代码生成器
 */

namespace Mooeen\Scaffold\Adder;

class RouterAdder extends Adder
{
    private array $config;

    public function start($app, $controller, $route, ?string $origin = null): bool
    {
        // plan-53 出身:包路由插包自己的 routes/admin.php(同款插入标记);写权硬线把闸
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);

        $this->config   = $this->utility->getConfig('controller.' . $app);
        [$method, $url] = explode(' ', trim($route, '/'));
        if ($this->originCtx !== null) {
            $router_file   = $this->originCtx->pathFor('route');
            $file_relative = $this->relDisplay($router_file, $this->originCtx);
        } else {
            $router_file   = base_path('/') . $this->config['route'];
            $file_relative = './' . $this->config['route'];
        }
        $file_txt = $this->filesystem->get($router_file);

        $route_str = "Route::{$method}('{$url}', [{$controller['class']}::class, '{$controller['action']}']);";
        if (str_contains($file_txt, $route_str)) {
            $this->console()->exists($file_relative, "Route {$method} {$url}");

            return false;
        }

        $insert_holder = '// :insert_code_here:do_not_delete';
        $codes         = [];
        $codes[]       = $route_str;
        $codes[]       = PHP_EOL;
        $codes[]       = $this->getTabs(1) . $insert_holder;

        $file_txt = str_replace($insert_holder, implode(PHP_EOL, $codes), $file_txt);
        $this->filesystem->put($router_file, $file_txt);
        $this->console()->added($file_relative, "Route {$method} {$url}");

        return true;
    }
}
