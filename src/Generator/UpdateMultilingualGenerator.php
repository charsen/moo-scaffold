<?php

namespace Mooeen\Scaffold\Generator;

/**
 * Update Multilingual Generator
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateMultilingualGenerator extends Generator
{
    /**
     * 只做增量，不做替换，因为可能会有手工润色
     */
    public function start(): bool
    {
        $languages = $this->utility->getConfig('languages');
        $files     = ['model', 'validation', 'db'];

        $all_fields     = $this->utility->getLangFields();
        $all_field_keys = array_keys($all_fields);

        $all_enums     = $this->utility->getEnumWords();
        $all_enum_keys = array_keys($all_enums);

        foreach ($files as $file_name) {
            foreach ($languages as $lang) {
                $data = $this->getLanguage($file_name, $lang);
                if ($file_name === 'model') {
                    $this->compileModel($file_name, $lang, $all_enums, $all_enum_keys, $data);
                } elseif ($file_name === 'validation') {
                    $this->compileValidation($file_name, $lang, $all_fields, $all_field_keys, $data);
                } elseif ($file_name === 'db') {
                    $this->compileDBFields($file_name, $lang, $all_fields, $all_field_keys, $data);
                }
            }
        }

        return true;
    }

    /**
     * 生成 Model 枚举字段的多语言文件
     */
    private function compileModel($file_name, $lang, array $all_enums, array $all_enum_keys, array $data): void
    {
        $old_alias = array_keys($data);
        $new_alias = array_diff($all_enum_keys, $old_alias);

        // 添加增量
        foreach ($new_alias as $alias) {
            $data[$alias] = $all_enums[$alias][$lang];
            $data[$alias] = str_replace("'", '&apos;', $data[$alias]);

            if ($lang === 'en') {
                $data[$alias] = ucwords($data[$alias]);
            }
        }

        // 去掉已删除，及更新旧的值
        foreach ($old_alias as $alias) {
            if (in_array($alias, $all_enum_keys, true)) {
                $data[$alias] = $all_enums[$alias][$lang];
            } else {
                unset($data[$alias]);
            }
        }

        // 格式化代码
        $code   = ['<?php'];
        $code[] = '';
        $code[] = 'return [';
        foreach ($data as $alias => $word) {
            $word   = str_replace("'", '&apos;', $word);
            $code[] = $this->getTabs(1) . "'{$alias}' => '{$word}',";
        }
        $code[] = '];';
        $code[] = '';

        $this->updateFile($file_name, $lang, implode("\n", $code));
    }

    /**
     * 生成 DB 的多语言文件
     */
    private function compileDBFields($file_name, $lang, array $all_fields, array $all_field_keys, array $data): void
    {
        $old_key = array_keys($data);
        $new_key = array_diff($all_field_keys, $old_key);

        // 增量
        foreach ($new_key as $key) {
            $data[$key] = $all_fields[$key][$lang];
            $data[$key] = str_replace("'", '&apos;', $data[$key]);

            if ($lang === 'en') {
                $data[$key] = ucwords($data[$key]);
            }
        }

        // 去掉已删除，及更新旧的值
        foreach ($old_key as $key) {
            if (in_array($key, $all_field_keys, true)) {
                $data[$key] = $all_fields[$key][$lang];
            } else {
                unset($data[$key]);
            }
        }

        // 格式化代码
        $code   = ['<?php'];
        $code[] = '';
        $code[] = 'return [';
        foreach ($data as $key => $word) {
            $word   = str_replace("'", '&apos;', $word);
            $code[] = $this->getTabs(1) . "'{$key}' => '{$word}',";
        }
        $code[] = '];';
        $code[] = '';

        $this->updateFile($file_name, $lang, implode("\n", $code));
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileValidation($file_name, $lang, array $all_fields, array $all_field_keys, array $data): void
    {

        $file_txt  = $this->getLanguage($file_name, $lang, true);
        $file_data = $this->getLanguage($file_name, $lang);
        $old_keys  = isset($file_data['attributes']) ? array_keys($file_data['attributes']) : [];
        $new_keys  = array_diff($all_field_keys, $old_keys);

        // 增量
        $rebuild_data = $file_data['attributes'] ?? [];
        foreach ($new_keys as $key) {
            $rebuild_data[$key] = $all_fields[$key][($lang === 'zh-CN' ? 'zh-CN' : $lang)];
            if ($lang === 'en') {
                $rebuild_data[$key] = ucwords($rebuild_data[$key]);
            }
        }

        // 去掉已删除，及更新旧的值
        foreach ($old_keys as $key) {
            if (in_array($key, $all_field_keys, true)) {
                $rebuild_data[$key] = $all_fields[$key][($lang === 'zh-CN' ? 'zh-CN' : $lang)];
            } else {
                unset($rebuild_data[$key]);
            }
        }

        $code = ["'attributes' => ["];
        foreach ($rebuild_data as $key => $val) {
            $val    = str_replace("'", '&apos;', $val);
            $code[] = $this->getTabs(2) . "'{$key}' => '{$val}',";
        }

        $code[] = $this->getTabs(1) . '],';

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
     */
    private function updateFile($file_name, $lang, $code): void
    {
        $file          = $this->getLanguagePath($file_name, $lang);
        $relative_file = $this->getLanguagePath($file_name, $lang, true);
        $put           = $this->filesystem->put($file, $code);

        if ($put) {
            $this->command->info('+ ' . $relative_file . ' (Updated)');
        } else {
            $this->command->error('x ' . $relative_file . ' (Failed)');
        }
    }

    /**
     * 获取多语言文件路径
     */
    public function getLanguagePath(string $file_name = 'validation', string $language = 'en', bool $relative = false): string
    {
        $path = lang_path("{$language}/");
        if (! $this->filesystem->isDirectory($path)) {
            $this->filesystem->makeDirectory($path);
        }

        $path .= "{$file_name}.php";

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 获取多语言文件
     */
    public function getLanguage(string $file_name = 'validation', string $language = 'en', bool $to_string = false): mixed
    {
        $file = lang_path("{$language}/{$file_name}.php");

        if (! $this->filesystem->isFile($file)) {
            // 获取语言文件的默认模板数据
            $file = $this->getStubPath() . "lang/{$language}.{$file_name}.stub";
        }

        return $to_string ? $this->filesystem->get($file) : $this->filesystem->getRequire($file);
    }
}
