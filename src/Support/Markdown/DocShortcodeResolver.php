<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use InvalidArgumentException;

/**
 * plan-52:把文档 shortcode 目标解析成已有 scaffold 只读路由的 URL。
 *
 *   [[debug: app/[Folder/]Controller@action | 显示名]] → route('api.request', app/f/c/a)  —— 必须带 @action(调一个端点)
 *   [[api:   app/[Folder/]Controller[@action]]]        → route('api.list', app/f/c[/a]) —— @action 可选,缺则定位到整个控制器
 *   [[db:    Module.table]] / [[db: Module]]           → route('db.docs', schema[/table]) —— 表可选,缺则整模块
 *
 * 目标全是 GET 只读路由 → 生产环境也能点开（只读预览）。语法错（debug 缺 @action、段数不足、空模块、未知类型）
 * 产出 error 节点 → 渲染成红色错误 chip（ship-checklist:写错有可见报错不静默）。
 * 注意:api 走 api.list(完整文档页),不是 api.show(那是 AJAX 片段,新窗打开只有裸 HTML);api 跟 db 对齐——
 * 控制器级(无 @action)/ 模块级(无 .table)都成立,是常见写法(在文档里指"这个控制器/这个模块")。
 * 不做"端点/表是否存在"的远端校验（要全量 catalog，每个 shortcode 都查太重）——目标页自身会优雅兜底。
 */
final class DocShortcodeResolver
{
    public function resolve(string $type, string $target, ?string $label): DocShortcodeNode
    {
        $type   = strtolower(trim($type));
        $target = trim($target);
        $label  = $label !== null ? trim($label) : null;
        if ($label === '') {
            $label = null;
        }

        try {
            return match ($type) {
                'debug' => $this->debugNode($target, $label),
                'api'   => $this->apiDocNode($target, $label),
                'db'    => $this->dbNode($target, $label),
                default => $this->error("未知 shortcode 类型「{$type}」（支持 debug / api / db）"),
            };
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /** [[debug: …@action]] → 接口调试页(完整页面)。调试针对单个端点,@action 必填。 */
    private function debugNode(string $target, ?string $label): DocShortcodeNode
    {
        ['app' => $app, 'folder' => $folder, 'controller' => $controller, 'action' => $action]
            = $this->parseApiTarget($target, requireAction: true);

        $url  = route('api.request', ['app' => $app, 'f' => $folder, 'c' => $controller, 'a' => $action]);
        $text = $label ?? ($controller . '@' . $action);

        return new DocShortcodeNode('debug', $text, $url);
    }

    /** [[api: …Controller[@action]]] → 接口文档页(完整页面,api.list)。@action 可选:缺则定位到整个控制器。 */
    private function apiDocNode(string $target, ?string $label): DocShortcodeNode
    {
        ['app' => $app, 'folder' => $folder, 'controller' => $controller, 'action' => $action]
            = $this->parseApiTarget($target, requireAction: false);

        $params = ['app' => $app, 'f' => $folder, 'c' => $controller];
        if ($action !== null) {
            $params['a'] = $action;
        }

        $url  = route('api.list', $params);
        $text = $label ?? ($action !== null ? $controller . '@' . $action : $controller);

        return new DocShortcodeNode('api', $text, $url);
    }

    /**
     * 拆 app/[Folder/]Controller[@action]。@action 可选(由 requireAction 决定缺时是否报错)。
     *
     * @return array{app:string,folder:string,controller:string,action:?string}
     */
    private function parseApiTarget(string $target, bool $requireAction): array
    {
        $left   = $target;
        $action = null;
        if (str_contains($target, '@')) {
            [$left, $action] = explode('@', $target, 2);
            $action          = trim($action);
            if ($action === '') {
                $action = null;
            }
        }
        if ($requireAction && $action === null) {
            throw new InvalidArgumentException("接口调试 shortcode 需指定 @action：{$target}");
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', $left)), static fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            throw new InvalidArgumentException("shortcode 格式应为 app/[Folder/]Controller[@action]：{$target}");
        }
        $app        = array_shift($parts);
        $controller = array_pop($parts);
        $folder     = $parts !== [] ? implode('/', $parts) : 'Index';

        return ['app' => $app, 'folder' => $folder, 'controller' => $controller, 'action' => $action];
    }

    private function dbNode(string $target, ?string $label): DocShortcodeNode
    {
        $module = $target;
        $table  = null;
        if (str_contains($target, '.')) {
            [$module, $table] = explode('.', $target, 2);
        }
        $module = trim($module);
        $table  = $table !== null ? trim($table) : null;
        if ($module === '') {
            throw new InvalidArgumentException("shortcode 缺少模块名：{$target}");
        }

        $params = ['schema' => $module];
        if ($table !== null && $table !== '') {
            $params['table'] = $table;
        }
        $url  = route('db.docs', $params);
        $text = $label ?? ($table ? "{$module}.{$table}" : $module);

        return new DocShortcodeNode('db', $text, $url);
    }

    private function error(string $msg): DocShortcodeNode
    {
        return new DocShortcodeNode('error', $msg, null, true);
    }
}
