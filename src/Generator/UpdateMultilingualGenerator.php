<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:02
 * @Description: Update Multilingual Generator
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;

class UpdateMultilingualGenerator extends Generator
{
    /**
     * 只做增量，不做替换，因为可能会有手工润色
     */
    public function start(?string $schema = null): bool
    {
        // plan-53 i18n 分流:包 schema 流水线 → 只写包 lang/(该包各表用到的词条子集,
        // MergingLoader 随包分发到任何 host);host / 未指定 schema → 全量写 host(原行为零变化)。
        // 词条 key 全局共享(同名列跨表一致),host 全量文件含包字段词条无害 — 运行时 host 优先。
        if ($schema !== null) {
            $menus  = $this->utility->getTables();
            $origin = $menus[$schema]['origin'] ?? null;
            if ($origin !== null) {
                return $this->startForPackage($schema, $origin);
            }
        }

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
     * plan-53:把「包各表用到的字段 / 枚举词条」增量写进包 lang/{locale}/{model,db,validation}.php。
     * 不动 host lang;子集外的旧 key **保留不删**——包 lang 是混合所有权(schema 派生词条 + 包内
     * 手写 feature 词条如 copy_mode / target_role_name,被控制器 __() 实际引用),删除同步会把
     * 手写词条当"已移除字段"误删(2026-07-04 moo-system 真机验收 catch)。host 全量路径仍删除同步。
     */
    private function startForPackage(string $schema, string $origin): bool
    {
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);

        $menus  = $this->utility->getTables();
        $tables = array_keys($menus[$schema]['tables'] ?? []);

        // 该包各表的字段名集合 + 枚举 label 键集合({field}_{alias})
        $fieldKeys = [];
        $enumKeys  = [];
        $allEnums  = $this->utility->getEnums(false);
        foreach ($tables as $t) {
            $tableAttr = $this->utility->getOneTable((string) $t);
            foreach (array_keys((array) ($tableAttr['fields'] ?? [])) as $f) {
                $fieldKeys[$f] = true;
            }
            foreach ((array) ($allEnums[$t] ?? []) as $field_name => $words) {
                foreach (array_keys((array) $words) as $alias) {
                    if (str_starts_with((string) $alias, '__pending_')) {
                        continue;
                    }
                    $enumKeys[$field_name . '_' . $alias] = true;
                }
            }
        }

        $all_fields     = array_intersect_key($this->utility->getLangFields(), $fieldKeys);
        $all_field_keys = array_keys($all_fields);
        $all_enums      = array_intersect_key($this->utility->getEnumWords(), $enumKeys);
        $all_enum_keys  = array_keys($all_enums);

        $languages = $this->utility->getConfig('languages');
        foreach (['model', 'validation', 'db'] as $file_name) {
            foreach ($languages as $lang) {
                $data = $this->getLanguage($file_name, $lang);
                if ($file_name === 'model') {
                    $this->compileModel($file_name, $lang, $all_enums, $all_enum_keys, $data, preserve_unknown: true);
                } elseif ($file_name === 'validation') {
                    $this->compileValidation($file_name, $lang, $all_fields, $all_field_keys, $data, preserve_unknown: true);
                } elseif ($file_name === 'db') {
                    $this->compileDBFields($file_name, $lang, $all_fields, $all_field_keys, $data, preserve_unknown: true);
                }
            }
        }

        return true;
    }

    /**
     * 生成 Model 枚举字段的多语言文件
     */
    private function compileModel($file_name, $lang, array $all_enums, array $all_enum_keys, array $data, bool $preserve_unknown = false): void
    {
        $this->compileSimplePhpFile($file_name, $lang, $all_enums, $all_enum_keys, $data, $preserve_unknown);
    }

    /**
     * 生成 DB 的多语言文件
     */
    private function compileDBFields($file_name, $lang, array $all_fields, array $all_field_keys, array $data, bool $preserve_unknown = false): void
    {
        $this->compileSimplePhpFile($file_name, $lang, $all_fields, $all_field_keys, $data, $preserve_unknown);
    }

    /**
     * 增量同步并写入 key => translation 结构的 PHP 语言文件
     *
     * $preserve_unknown = true(包管线):$all_keys 之外的旧 key 保留原值不删 —— 包 lang 里有
     * 手写 feature 词条,子集删除同步会误删;host 全量路径 false,维持删除同步原语义。
     */
    private function compileSimplePhpFile(string $file_name, string $lang, array $all_items, array $all_keys, array $data, bool $preserve_unknown = false): void
    {
        $old_keys = array_keys($data);

        // 增量添加:存「原始值」进 $data,转义统一留到下面 emit 循环做一次。
        // 原来这里先 escapeLangValue,emit 又 escape 一次 → 双重转义;`&apos;` 替换碰巧幂等才没暴露,
        // 换成正确的 escapePhpString 后双重转义会把 Tom's 写成 Tom\'s。改用 stringifyLangValue 存原始值
        // (跟下方"更新已有"分支 + compileValidation 的增量分支一致,单次转义,2026-06-11 修)。
        foreach (array_diff($all_keys, $old_keys) as $key) {
            $value      = $this->stringifyLangValue($all_items[$key][$lang] ?? '');
            $data[$key] = $lang === 'en' ? ucwords($value) : $value;
        }

        // 删除已移除的项，更新已有的值(preserve_unknown 时未知 key 保留原值)
        foreach ($old_keys as $key) {
            if (in_array($key, $all_keys, true)) {
                $data[$key] = $this->stringifyLangValue($all_items[$key][$lang] ?? '');
            } elseif (! $preserve_unknown) {
                unset($data[$key]);
            }
        }

        $code   = ['<?php'];
        $code[] = '';
        $code[] = 'return [';
        foreach ($data as $key => $word) {
            $word = $this->escapeLangValue($word);
            // plan-40 §二 C-11:i18n key 也走 PHP escape,防 `field_name: a',1);system('id');//` 注入数组结构
            $code[] = $this->getTabs(1) . "'" . $this->escapePhpString($key) . "' => '{$word}',";
        }
        $code[] = '];';
        $code[] = '';

        $this->updateFile($file_name, $lang, implode("\n", $code));
    }

    /**
     * @throws FileNotFoundException
     */
    private function compileValidation($file_name, $lang, array $all_fields, array $all_field_keys, array $data, bool $preserve_unknown = false): void
    {

        $file_txt  = $this->getLanguage($file_name, $lang, true);
        $file_data = $this->getLanguage($file_name, $lang);
        $old_keys  = isset($file_data['attributes']) ? array_keys($file_data['attributes']) : [];
        $new_keys  = array_diff($all_field_keys, $old_keys);

        // 增量
        $rebuild_data = $file_data['attributes'] ?? [];
        foreach ($new_keys as $key) {
            $rebuild_data[$key] = $this->stringifyLangValue($all_fields[$key][($lang === 'zh-CN' ? 'zh-CN' : $lang)] ?? '');
            if ($lang === 'en') {
                $rebuild_data[$key] = ucwords($rebuild_data[$key]);
            }
        }

        // 去掉已删除，及更新旧的值(preserve_unknown 时未知 key 保留原值)
        foreach ($old_keys as $key) {
            if (in_array($key, $all_field_keys, true)) {
                $rebuild_data[$key] = $this->stringifyLangValue($all_fields[$key][($lang === 'zh-CN' ? 'zh-CN' : $lang)] ?? '');
            } elseif (! $preserve_unknown) {
                unset($rebuild_data[$key]);
            }
        }

        $code = ["'attributes' => ["];
        foreach ($rebuild_data as $key => $val) {
            $val = $this->escapeLangValue($val);
            // plan-40 §二 C-11:i18n attribute key 同样 escape
            $code[] = $this->getTabs(2) . "'" . $this->escapePhpString($key) . "' => '{$val}',";
        }

        $code[] = $this->getTabs(1) . '],';

        // 只替换 attributes 部分。用 callback 而非字符串替换:翻译值里的 `$数字`(如 "Fee $50")
        // 在 preg_replace 字符串替换里会被当成反向引用 → "Fee 0" 静默损坏(2026-06-09 修)。
        $replacement = implode("\n", $code);
        $file_txt    = preg_replace_callback(
            '/\'attributes\'[\s]*=>[\s]*\[.*\],/is',
            static fn () => $replacement,
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
            $this->console()->updated($relative_file);
        } else {
            $this->console()->failed($relative_file);
        }
    }

    /**
     * 获取多语言文件路径
     */
    public function getLanguagePath(string $file_name = 'validation', string $language = 'en', bool $relative = false): string
    {
        // plan-53:包流水线时落包 lang/(originCtx 由 startForPackage 设);host 照旧 lang_path
        $path = $this->originCtx !== null
            ? rtrim($this->originCtx->pathFor('lang'), '/') . "/{$language}/"
            : lang_path("{$language}/");
        $this->checkDirectory($path);

        $path .= "{$file_name}.php";

        return $relative ? $this->relDisplay($path, $this->originCtx) : $path;
    }

    /**
     * 获取多语言文件
     */
    public function getLanguage(string $file_name = 'validation', string $language = 'en', bool $to_string = false): mixed
    {
        $file = $this->getLanguagePath($file_name, $language);

        if (! $this->filesystem->isFile($file)) {
            // 获取语言文件的默认模板数据
            $file = $this->getStubPath() . "lang/{$language}.{$file_name}.stub";
        }

        return $to_string ? $this->filesystem->get($file) : $this->filesystem->getRequire($file);
    }

    private function escapeLangValue(mixed $value): string
    {
        // 值最终进单引号 PHP 串(`'{$word}'`),用 escapePhpString 转义引号和反斜杠(跟 key 一致)。
        // 原来用「把撇号替换成 &apos;」两个问题:① 漏转义反斜杠 → 值含/结尾反斜杠时 `'Path\'`
        // 让转义吃掉闭引号,整个语言文件 PHP 语法崩;② 把撇号永久变成字面量 &apos;(Tom's→Tom&apos;s)。
        // escapePhpString 同时正确处理两者(2026-06-11 修)。
        return $this->escapePhpString($this->stringifyLangValue($value));
    }

    private function stringifyLangValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '' : $encoded;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }
}
