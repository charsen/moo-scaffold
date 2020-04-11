<?php

namespace Charsen\Scaffold\Foundation;

/**
 * Actions
 *
 * @author : charsen
 * @date: 2018-12-05 10:48
 */

class Actions
{
    private $data = NULL;

    function __construct()
    {
        $this->data = config('actions.actions');
    }

    /**
     * 获取所有数据
     *
     * @return \Illuminate\Support\Collection
     */
    public function get()
    {
        $result = $this->recursion($this->data);

        return collect($result);
    }

    /**
     * 检查并移除不存在的键值
     *
     * @param array $data
     *
     * @return array
     */
    public function checkAndRemove(array $data)
    {
        $keys = $this->getKeys()->toArray();
        foreach ($data as $k => $v)
        {
            if (! in_array($v, $keys))
            {
                unset($data[$k]);
            }
        }

        return $data;
    }

    /**
     * 获取所有键值
     *
     * @return \Illuminate\Support\Collection
     */
    public function getKeys()
    {
        $result = $this->recursionKeys($this->data);

        return collect($result);
    }

    /**
     * 递归获取键值
     *
     * @param array $data
     * @param array $all_keys
     * @return array
     */
    private function recursionKeys($data, &$all_keys = [])
    {
        if (empty($data)) return [];

        foreach ($data as $key => $val)
        {
            $lang = __('actions.' . $key);
            if ($lang == 'actions.' . $key)
            {
                // 移除没多语言的项目
                unset($data[$key]);
                continue;
            }
            $all_keys[] = $key;
            if (preg_match("/[\w\-]+Controller$/", $key))
            {
                foreach ($val as $action)
                {
                    $action_lang = __('actions.' . $action);
                    // 只保留有多语言的功能
                    if ($action_lang != 'actions.' . $action)
                    {
                        $all_keys[] = $action;
                    }
                }
            }
            else
            {
                $this->recursionKeys($val, $all_keys);
            }
        }

        return $all_keys;
    }

    /**
     * 递归处理数据
     *
     * @param       $data
     *
     * @return mixed
     */
    private function recursion($data)
    {
        if (empty($data)) return [];

        foreach ($data as $key => &$val)
        {
            $lang = __('actions.' . $key);
            if ($lang == 'actions.' . $key)
            {
                // 移除没多语言的项目
                unset($data[$key]);
                continue;
            }

            if (preg_match("/[\w\-]+Controller$/", $key))
            {
                $temp           = [];
                foreach ($val as $action)
                {
                    $action_lang = __('actions.' . $action);
                    // 只保留有多语言的功能
                    if ($action_lang != 'actions.' . $action)
                    {
                        $temp[$action] = $action_lang;
                    }
                }

                $data[$key] = [
                    'name'    => $lang,
                    'actions' => $temp,
                ];
            }
            else
            {
                $data[$key] = [
                    'name'     => $lang,
                    'children' => $this->recursion($val),
                ];
            }
        }

        return $data;
    }

}
