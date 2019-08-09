<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Request
 *
 * @author Charsen https://github.com/charsen
 */
class CreateRequestGenerator extends Generator
{
    /**
     * @param      $controller
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($controller, $force = false)
    {

    }

    /**
     * 生成 检验规
     *
     * @param  array $fields
     * @param array  $dictionaries_ids
     *
     * @return string
     */
    private function buildRules(array $fields, array $dictionaries_ids)
    {
        $rules = $this->rebuildFieldsRules($fields, $dictionaries_ids);
        //var_dump($rules);

        // 在列表页附加 分页码参数
        $front_code    = ["'index' => ["];
        $front_code[]  = $this->getTabs(3) . "'page' => 'sometimes|required|integer|min:1',";
        $front_code[]  = $this->getTabs(3) . "'page_limit' => 'sometimes|required|integer|min:1',";
        $front_code[]  = $this->getTabs(2) . '],';
        $front_code[]  = $this->getTabs(2) . "'trashed' => [";
        $front_code[]  = $this->getTabs(3) . "'page' => 'sometimes|required|integer|min:1',";
        $front_code[]  = $this->getTabs(3) . "'page_limit' => 'sometimes|required|integer|min:1',";
        $front_code[]  = $this->getTabs(2) . '],';

        // destroyBatch, restoreBatch
        $end_code      = [$this->getTabs(2) . "'destroyBatch' => ["];
        $end_code[]    = $this->getTabs(3) . "'ids' => 'required|array',";
        $end_code[]    = $this->getTabs(3) . "'force' => 'sometimes|required|boolean',";
        $end_code[]    = $this->getTabs(2) . '],';
        $end_code[]    = $this->getTabs(2) . "'restoreBatch' => [";
        $end_code[]    = $this->getTabs(3) . "'ids' => 'required|array',";
        $end_code[]    = $this->getTabs(2) . '],';

        // create & update action
        $create_code = [$this->getTabs(2) . "'create' => ["];
        $update_code = [$this->getTabs(2) . "'update' => ["];
        foreach ($rules as $field_name => $rule)
        {
            $create_code[] = $this->getTabs(3) . "'{$field_name}' => '{$rule}',";
            $update_code[] = $this->getTabs(3) . "'{$field_name}' => 'sometimes|{$rule}',";
        }
        $create_code[] = $this->getTabs(2) . '],';
        $update_code[] = $this->getTabs(2) . '],';

        return implode("\n", array_merge($front_code, $create_code, $update_code, $end_code));
    }

    /**
     * 重建 字段的规则
     *
     * @param  array $fields
     * @param array  $dictionaries_ids
     *
     * @return array
     */
    private function rebuildFieldsRules(array $fields, array $dictionaries_ids)
    {
        //todo, 根据 索引 unique 附加 unique 规则
        $rules = [];
        foreach ($fields as $field_name => $attr)
        {
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at']))
            {
                continue;
            }

            $filed_rules        = [];
            if ($attr['require'])
            {
                $filed_rules[]  = 'required';
            }

            if ($attr['allow_null'])
            {
                $filed_rules[]  = 'nullable';
            }

            if (in_array($attr['type'], ['int', 'tinyint', 'bigint']))
            {
                // 整数转浮点数时，需要调整为 numeric
                if (isset($attr['format']) && strstr($attr['format'], 'intval:'))
                {
                    $filed_rules[] = 'numeric';
                }
                else
                {
                    $filed_rules[] = 'integer';
                }

                if (isset($attr['unsigned']) && ! isset($dictionaries_ids[$field_name]))
                {
                    $filed_rules[] = 'min:0';
                }
            }

            if ($attr['type'] == 'boolean')
            {
                $filed_rules[] = 'in:0,1';
            }

            if ($attr['type'] == 'date' || $attr['type'] == 'datetime')
            {
                $filed_rules[] = 'date';
            }

            if (isset($attr['min_size']) && in_array($attr['type'], ['char', 'varchar']))
            {
                $filed_rules[] = 'min:' . $attr['min_size'];
            }

            if (isset($attr['size']) && in_array($attr['type'], ['char', 'varchar']))
            {
                $filed_rules[] = 'max:' . $attr['size'];
            }
            if (isset($dictionaries_ids[$field_name]))
            {
                $filed_rules[] = 'in:' . implode(',', $dictionaries_ids[$field_name]);
            }
            $rules[$field_name] = implode('|', $filed_rules);
        }

        return $rules;
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
        return $this->buildStub($meta, $this->getStub('request'));
    }
}
