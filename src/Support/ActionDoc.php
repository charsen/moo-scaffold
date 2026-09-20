<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * API action 的 **DocBlock / 反射解析**（2026-09-19 自 `Utility` 外迁）。
 *
 * 与 {@see ActionMeta} 的分工：本类负责「从反射与 DocBlock **读出**字段」，ActionMeta 负责
 * 「把读出的（或手写 YAML 里的）字段**归一**成稳定形状」。两者都只做纯计算 —— 不碰文件系统。
 *
 * 唯一的外界依赖是配置项 `scaffold.languages`（多语言名称的键序），走 `config()` 直读；
 * `Utility::parseYamlFile()` **没有**跟着过来，因为它依赖 `$this->filesystem`，留在原处。
 *
 * 迁来时的对应关系（旧名在 `Utility` 上）：
 *   `parsePMCNames()`           → `parsePMCNames()`
 *   `parseActionInfo()`         → `parseActionInfo()`
 *   `parseActionName()`         → `parseActionName()`
 *   `parseActionDesc()`         → `parseActionDesc()`
 *   `getActionRequestClass()`   → `getActionRequestClass()`
 *   `parseByLanguages()`        → `parseByLanguages()`（private）
 *   `normalizeDocComment()`     → `normalizeDocComment()`（private）
 */
final class ActionDoc
{
    /**
     * 解析 包名、模块名、控制器名
     */
    public static function parsePMCNames(ReflectionClass $reflectionClass): array
    {
        $data        = [];
        $doc_comment = self::normalizeDocComment($reflectionClass->getDocComment());

        preg_match('/@package\_name\s(.*)\n/', $doc_comment, $package_name);
        preg_match('/@module\_name\s(.*)\n/', $doc_comment, $module_name);
        preg_match('/@controller\_name\s(.*)\n/', $doc_comment, $controller_name);

        $package_name    = empty($package_name) ? '' : $package_name[1];
        $module_name     = empty($module_name) ? '' : $module_name[1];
        $controller_name = empty($controller_name) ? '' : $controller_name[1];

        $data['package']['name']    = self::parseByLanguages($package_name);
        $data['module']['name']     = self::parseByLanguages($module_name);
        $data['controller']['name'] = self::parseByLanguages($controller_name);

        return $data;
    }

    /**
     * 解析动作多语言名称
     */
    public static function parseActionInfo(ReflectionMethod $reflectionMethod): array
    {
        $data        = [];
        $doc_comment = self::normalizeDocComment($reflectionMethod->getDocComment());

        preg_match('/@acl\s(.*)\n/', $doc_comment, $acl);
        $data['whitelist'] = empty($acl);
        $temp_string       = (empty($acl) ? '' : $acl[1]);
        $data['name']      = self::parseByLanguages($temp_string);

        preg_match('/desc:([^\|]*)[\|}]/i', $temp_string, $temp);
        $data['desc'] = empty($temp) ? '' : trim($temp[1]);

        return $data;
    }

    /**
     * 解析动作第一行作为名称
     */
    public static function parseActionName(ReflectionMethod $reflectionMethod): string
    {
        $doc_comment = self::normalizeDocComment($reflectionMethod->getDocComment());

        preg_match_all('#^\s*\*(.*)#m', $doc_comment, $lines);

        return isset($lines[1][0]) ? trim($lines[1][0]) : '';
    }

    /**
     * 解析 action 描述 —— docblock 第一行是 name(parseActionName 取),第 2 行起的散文行是 desc。
     * 跳过空行 + @param/@return 等 tag 行。返回多行 list(跟 yaml 里 desc: [] 同形)。
     *
     * @return list<string>
     */
    public static function parseActionDesc(ReflectionMethod $reflectionMethod): array
    {
        $doc_comment = self::normalizeDocComment($reflectionMethod->getDocComment());

        // (?!/) 排除收尾的 `*/` 行(否则会捕到一个 '/' 混进 desc);name 行仍是第一行,shift 掉
        preg_match_all('#^\s*\*(?!/)(.*)#m', $doc_comment, $lines);
        $rows = $lines[1] ?? [];
        array_shift($rows);   // 丢掉第一行(name)

        $desc = [];
        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '' || str_starts_with($row, '@')) {
                continue;
            }
            // 去行首 markdown 列表符(- / * / –),展示端(说明卡)自带项目符,避免「· -」双重符号
            $row = (string) preg_replace('/^[-*–]\s+/u', '', $row);
            if ($row === '') {
                continue;
            }
            $desc[] = $row;
        }

        return $desc;
    }

    /**
     * 解析动作参数中的 Request 类
     * ! 变量名，必须是 $request !
     */
    public static function getActionRequestClass(ReflectionMethod $reflectionAction): ?object
    {
        $result            = null;
        $reflection_params = $reflectionAction->getParameters();

        foreach ($reflection_params as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if ($param->getName() === 'request') {
                $param_class = $type->getName();
                if (! class_exists($param_class)) {
                    continue;
                }

                $result = (new ReflectionClass($param_class))->newInstanceWithoutConstructor();
                break;
            }
        }

        return $result;
    }

    /**
     * 根据语言解析
     *
     * 内部专用：调用点只有 {@see parsePMCNames()} 与 {@see parseActionInfo()}。
     */
    private static function parseByLanguages(string $string): array
    {
        $languages = config('scaffold.languages');
        $string    = str_replace("'", '&apos;', $string);
        $data      = [];

        foreach ($languages as $lang) {
            preg_match('/' . $lang . ':([^\|\,]*)[\|\,}]/i', $string, $temp);
            $data[$lang] = empty($temp) ? '' : trim($temp[1]);
        }

        return $data;
    }

    private static function normalizeDocComment(string|false $docComment): string
    {
        return $docComment === false ? '' : $docComment;
    }
}
