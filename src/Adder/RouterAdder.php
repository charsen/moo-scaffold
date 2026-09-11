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

        $this->config = $this->utility->getConfig('controller.' . $app);

        // 2026-09-11：路由串格式不合法时立刻中止。原实现直接解构 explode 结果，
        // 只给一个词（如 `get`）时 $url 未定义 → warning + 生成残缺路由串；多给一段则被静默丢弃。
        $route_parts = preg_split('/\s+/', trim(trim((string) $route), '/'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($route_parts) !== 2) {
            $this->console()->failed((string) $route, '路由格式应为「METHOD path」，例如 `get light/memos`');

            return false;
        }
        [$method, $url] = $route_parts;

        // 2026-09-11 输入守卫：method / class / action 直接拼进生成的 PHP 代码，$url 进单引号串。
        // 原先零校验 —— `get'; system('id'); //` 之类可直接改写宿主路由文件。
        if (! $this->isPhpIdentifier($method)) {
            return $this->invalidInput($method, 'HTTP method 非法：只允许字母/数字/下划线');
        }
        $controller_class  = (string) ($controller['class'] ?? '');
        $controller_action = (string) ($controller['action'] ?? '');
        if (! $this->isPhpQualifiedName($controller_class)) {
            return $this->invalidInput($controller_class, '控制器类名非法：应为 App\\...\\XxxController 形式的全限定名');
        }
        if (! $this->isPhpIdentifier($controller_action)) {
            return $this->invalidInput($controller_action, 'action 名非法：只允许字母/数字/下划线，且不能以数字开头');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return $this->invalidInput($url, '路由路径不能包含控制字符或换行');
        }

        if ($this->originCtx !== null) {
            $router_file   = $this->originCtx->pathFor('route');
            $file_relative = $this->relDisplay($router_file, $this->originCtx);
        } else {
            $router_file   = base_path('/') . $this->config['route'];
            $file_relative = './' . $this->config['route'];
        }

        // 2026-09-11：路由文件缺失时给可读报错。原实现直接 get()，抛未捕获的
        // FileNotFoundException 中断整个 moo:adder（对比 CreateControllerGenerator::insertRoutes 有 isFile 守卫）。
        if (! $this->filesystem->isFile($router_file)) {
            $this->console()->failed($file_relative, '路由文件不存在，已中止');

            return false;
        }

        $file_txt = (string) $this->filesystem->get($router_file);

        // 2026-09-11：$url 进单引号 PHP 串，走 escapePhpString；class / action 已校验为
        // 标识符（含 \ 的全限定名），无需再转义。
        $route_str = "Route::{$method}('" . $this->escapePhpString($url) . "', [{$controller_class}::class, '{$controller_action}']);";
        if (str_contains($file_txt, $route_str)) {
            $this->console()->exists($file_relative, "Route {$method} {$url}");

            return false;
        }

        $insert_holder = '// :insert_code_here:do_not_delete';

        // 2026-09-11：插入标记缺失时中止。原实现 str_replace 未命中 → 原样 put 回文件，
        // 内容一字未改却照样打印 added，用户以为路由已挂上。
        if (! str_contains($file_txt, $insert_holder)) {
            $this->console()->failed($file_relative, "未找到插入标记 {$insert_holder}，已中止（未写入任何内容）");

            return false;
        }

        $codes   = [];
        $codes[] = $route_str;
        $codes[] = PHP_EOL;
        $codes[] = $this->getTabs(1) . $insert_holder;

        $file_txt = str_replace($insert_holder, implode(PHP_EOL, $codes), $file_txt);
        if ($this->filesystem->put($router_file, $file_txt) === false) {
            $this->console()->failed($file_relative, '写入失败，文件未变更');

            return false;
        }

        $this->console()->added($file_relative, "Route {$method} {$url}");

        return true;
    }
}
