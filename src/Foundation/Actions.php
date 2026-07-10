<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:02
 * @Description: Actions
 */

namespace Mooeen\Scaffold\Foundation;

/**
 * ACL 权限点组装（host 消费面）。
 *
 * @api 本类由**宿主项目**实例化消费（ACL 角色/权限页），moo-scaffold 仓内 0 调用属正常——
 *      勿因 grep 无引用判为死码（见 notes.md「grep 缺失≠死键」教训）。
 */
class Actions
{
    private array $data;

    private string $app;

    public function __construct(string $app = 'admin')
    {
        $this->app  = $app;
        $this->data = config('actions.' . $this->app . '.actions', []);
    }

    /**
     * 获取所有数据
     *
     * @api
     */
    public function get(): array
    {
        return $this->recursion($this->data);
    }

    /**
     * 获取已选中的权限点
     *
     * @api
     */
    public static function getCheckedActions(array $data, array &$res = []): array
    {
        foreach ($data as $key => $v) {
            if (! empty($v['checked'])) {
                foreach ($v['checked'] as $c) {
                    $res[] = (string) $c;
                }
            }

            if (! empty($v['children'])) {
                static::getCheckedActions($v['children'], $res);
            }
        }

        return $res;
    }

    /**
     * 给前端格式化权限点
     *
     * @api
     */
    public function formatActions($data, $role_actions, $parent_id = ''): array
    {
        $parent_id    = $parent_id === '' ? "app_{$this->app}" : $parent_id;
        $role_actions = array_map('strval', $role_actions);
        $res          = [];

        foreach ($data as $key => $v) {
            $key = (string) $key;
            $one = ['id' => $key, 'pid' => $parent_id, 'label' => $v['name']];

            if (! empty($v['children'])) {
                $one['checked']      = [];
                $one['children_ids'] = [];
                foreach ($v['children'] as $tmp => $tmp_one) {
                    $tmp                   = (string) $tmp;
                    $one['children_ids'][] = $tmp;
                    if (in_array($tmp, $role_actions, true)) {
                        $one['checked'][]     = $tmp;
                        $one['indeterminate'] = true;
                    }
                }
                $one['checked_all']   = count($one['checked']) === count($v['children']);
                $one['indeterminate'] = ! $one['checked_all'] && count($one['checked']) > 0;
                $one['children']      = static::formatActions($v['children'], $role_actions, $key);
            }

            $res[] = $one;
        }

        return $res;
    }

    /**
     * 获取所有 Actions 键值
     *
     * @api
     */
    public function getOnlyActionKeys(): array
    {
        return $this->recursionOnlyActions($this->data);
    }

    /**
     * 递归获取 Actions 键值
     */
    private function recursionOnlyActions(array $data, array &$all_actions = []): array
    {
        if (empty($data)) {
            return [];
        }

        foreach ($data as $key => $val) {
            if (preg_match('/controller\-[\w\-]+$/', $key)) {
                foreach ($val as $action) {
                    // 只保留有多语言的功能
                    if (__("actions.{$this->app}.{$action}") !== "actions.{$this->app}.{$action}") {
                        $all_actions[] = (string) $action;
                    }
                }
            } else {
                $this->recursionOnlyActions($val, $all_actions);
            }
        }

        return $all_actions;
    }

    /**
     * 递归处理数据
     */
    private function recursion(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        foreach ($data as $key => &$val) {
            $lang = __("actions.{$this->app}.{$key}");
            if ($lang === "actions.{$this->app}.{$key}") {
                // 移除没多语言的项目
                unset($data[$key]);

                continue;
            }

            if (preg_match('/controller\-[\w\-]+$/', $key)) {
                $temp = [];
                foreach ($val as $action) {
                    $action_lang = __("actions.{$this->app}.{$action}");
                    // 只保留有多语言的功能
                    if ($action_lang !== "actions.{$this->app}.{$action}") {
                        $temp[$action] = ['name' => $action_lang];
                    }
                }

                $data[$key] = [
                    'name'     => $lang,
                    'children' => $temp,
                ];
            } else {
                $data[$key] = [
                    'name'     => $lang,
                    'children' => $this->recursion($val),
                ];
            }
        }

        return $data;
    }
}
