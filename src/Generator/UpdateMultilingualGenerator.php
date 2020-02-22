<?php
namespace Charsen\Scaffold\Generator;

/**
 * Update Multilingual Generator
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateMultilingualGenerator extends Generator
{

    /**
     * 只做增量，不做替换，因为可能会有手工润色
     *
     */
    public function start()
    {
        $languages  = $this->utility->getConfig('languages');
        $files      = ['model', 'validation', 'db'];

        $all_fields             = $this->utility->getLangFields();
        $all_field_keys         = array_keys($all_fields);

        $all_dictionaries       = $this->utility->getDictionaryWords();
        $all_dictionary_alias   = array_keys($all_dictionaries);

        foreach ($files as $file_name)
        {
            foreach ($languages as $lang)
            {
                $data = $this->utility->getLanguage($file_name, $lang);
                if ($file_name == 'model')
                {
                    $this->compileModel($file_name, $lang, $all_dictionaries, $all_dictionary_alias, $data);
                }
                else if ($file_name == 'validation')
                {
                    $this->compileValidation($file_name, $lang, $all_fields, $all_field_keys, $data);

                }
                else if ($file_name == 'db')
                {
                    $this->compileDBFields($file_name, $lang, $all_fields, $all_field_keys, $data);
                }
            }
        }

        return true;
    }

    /**
     * 编译生成模型字典的多语言
     *
     * @param       $file_name
     * @param       $lang
     * @param array $all_dictionaries
     * @param array $all_dictionary_alias
     * @param array $data
     */
    private function compileModel($file_name, $lang, array $all_dictionaries, array $all_dictionary_alias, array $data)
    {
        $old_alias = array_keys($data);
        $new_alias = array_diff($all_dictionary_alias, $old_alias);

        foreach ($new_alias as $alias) {
            $data[$alias] = $all_dictionaries[$alias][$lang];
            $data[$alias] = str_replace("'", "&apos;", $data[$alias]);

            if ($lang == 'en') {
                $data[$alias] = ucwords($data[$alias]);
            }
        }

        // 格式化代码
        $code   = ["<?php"];
        $code[] = '';
        $code[] = 'return [';
        foreach ($data as $alias => $word) {
            $word   = str_replace("'", "&apos;", $word);
            $code[] = $this->getTabs(1) . "'{$alias}' => '{$word}',";
        }
        $code[] = '];';
        $code[] = '';

        return $this->updateFile($file_name, $lang, implode("\n", $code));
    }

    private function compileDBFields($file_name, $lang, array $all_fields, array $all_field_keys, array $data)
    {
        $old_key = array_keys($data);
        $new_key = array_diff($all_field_keys, $old_key);

        foreach ($new_key as $alias) {
            $data[$alias] = $all_fields[$alias][$lang];
            $data[$alias] = str_replace("'", "&apos;", $data[$alias]);

            if ($lang == 'en') {
                $data[$alias] = ucwords($data[$alias]);
            }
        }

        // 格式化代码
        $code   = ["<?php"];
        $code[] = '';
        $code[] = 'return [';
        foreach ($data as $key => $word) {
            $word   = str_replace("'", "&apos;", $word);
            $code[] = $this->getTabs(1) . "'{$key}' => '{$word}',";
        }
        $code[] = '];';
        $code[] = '';

        return $this->updateFile($file_name, $lang, implode("\n", $code));
    }

    /**
     * @param       $file_name
     * @param       $lang
     * @param array $all_fields
     * @param array $all_field_keys
     * @param array $data
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileValidation($file_name, $lang, array $all_fields, array $all_field_keys, array $data)
    {

        $file_txt       = $this->utility->getLanguage($file_name, $lang, true);
        $file_data      = $this->utility->getLanguage($file_name, $lang);
        $old_keys       = isset($file_data['attributes']) ? array_keys($file_data['attributes']) : [];
        $new_keys       = array_diff($all_field_keys, $old_keys);

        $rebuild_data   = $file_data['attributes'] ?? [];
        foreach ($new_keys as $key)
        {
            $rebuild_data[$key] = $all_fields[$key][($lang == 'zh-CN' ? 'cn' : $lang)];
            if ($lang == 'en')
            {
                $rebuild_data[$key] = ucwords($rebuild_data[$key]);
            }
        }

        $code = ["'attributes' => ["];

        foreach ($rebuild_data as $key => $val)
        {
            $val    = str_replace("'", "&apos;", $val);
            $code[] = $this->getTabs(2) . "'{$key}' => '{$val}',";
        }

        $code[] = $this->getTabs(1) . "],";

        // 只替换 attributes 部分
        $file_txt = preg_replace(
            '/\'attributes\'[\s]*=>[\s]*\[.*\],/is',
            implode("\n", $code),
            $file_txt
        );
        $this->updateFile($file_name, $lang, $file_txt);
    }

    /**
     * 更新文件内容
     *
     * @param $file_name
     * @param $lang
     * @param $code
     */
    private function updateFile($file_name, $lang, $code)
    {
        $file           = $this->utility->getLanguagePath($file_name, $lang);
        $relative_file  = $this->utility->getLanguagePath($file_name, $lang, true);
        $put  = $this->filesystem->put($file, $code);
        if ($put)
        {
            return $this->command->info('+ ' . $relative_file . ' (Updated)');
        }

        return $this->command->error('x ' . $relative_file . ' (Failed)');
    }

}
