<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * 聚合缓存的**唯一读取口径** —— `storage/scaffold/*.php` 这些由 `moo:fresh` 重建的产物，
 * 一律从这里读，别再各自 `getRequire`。
 *
 * **迁了什么**（2026-09-19 自 `Utility` 外迁，第 3 项 · 阶段 3b-3）：9 个方法，**方法体逐字未动**，
 * 只换了接收者与名字（去掉与类名重复的 `get*` 前缀，与 `Paths` 同批规则）：
 * `getOneTable`→{@see table()}、`getTables`→{@see tables()}、`getModels`→{@see models()}、
 * `getModelIds`→{@see modelIds()}、`getControllers`→{@see controllers()}、`getFields`→{@see fields()}、
 * `getEnums`→{@see enums()}、`getEnumWords`→{@see enumWords()}；
 * `dictionaryStats()` 名字本就够，未改。
 *
 * **为什么是 `final` + 全静态**（与 `Paths` 同形）：本类唯一的外部依赖是 `Filesystem`，而它
 * **无状态、也不读 `config()`** —— 读的是 `Paths::storage()` 下的绝对文件。既不需要容器解析，
 * 也不需要构造函数注入；`Filesystem` 由各方法就地 `new`（与 `Utility::__construct()` 里
 * `new Filesystem` 的既有形态一致）。代价是调用点不能替换实现 —— 但本仓对这些方法的既有测试
 * 本来就是**真写缓存文件**再读（见 `tests/Feature/Support/StorageRegistryTest.php`），没有替换需求。
 * 反过来若做成实例注入，4 个基类 + 全仓 `new XGenerator()` 共 ~76 处都要改构造函数，收益为零。
 *
 * **没跟着来的两个**（同一批 REGISTRY 组的邻居，但职责不是「读缓存」）：
 *   - `Utility::getLangFields()` —— 读 `scaffold/database/schema/_fields.yaml`（schema YAML，
 *     走 `Utility::parseYamlFile()`），不是 `storage/scaffold/` 的聚合缓存；
 *   - `Utility::getApps()` / `getAppTargets()` / `getExtraModules()` / `getControllerNamespaces()`
 *     —— app-target 组，且本就已委托 {@see AppTargetRegistry}。
 *
 * **⚠ 一处有意保留的死分支**：{@see controllers()} 的 `$merge_all = true` 臂目前**零调用点**
 * （全仓 10 个调用点一律显式传 `false`；唯一提 `true` 的是 `ScaffoldController` 与
 * `ScaffoldDashboardTest` 里两处「为什么不再用它」的注释）。外迁时**原样搬**、不顺手删 ——
 * 删它是 API 变更，要连注释与注释面锚点一起动，属独立决定。
 */
final class StorageRegistry
{
    /**
     * 获取一表数据表的数据
     *
     * 文件缺失时**抛 `InvalidArgumentException`**（不是 `FileNotFoundException`）—— 外迁前的
     * 既有形态，未改；它带表名定位信息，调用点按「表名打错」处理。
     *
     * @throws FileNotFoundException
     */
    public static function table(string $table_name): array
    {
        $fs   = new Filesystem;
        $file = Paths::storage() . "{$table_name}.php";

        if (! $fs->isFile($file)) {
            throw new InvalidArgumentException('Invalid Argument (Not Found).');
        }

        return $fs->getRequire($file);
    }

    /**
     * 获取 数据表 数据
     *
     * @throws FileNotFoundException
     */
    public static function tables(): array
    {
        return (new Filesystem)->getRequire(Paths::storage() . 'tables.php');
    }

    /**
     * 获取 模型 数据
     *
     * @throws FileNotFoundException
     */
    public static function models(): array
    {
        return (new Filesystem)->getRequire(Paths::storage() . 'models.php');
    }

    /**
     * 获取 模型ID 数据
     *
     * @throws FileNotFoundException
     */
    public static function modelIds(): array
    {
        return (new Filesystem)->getRequire(Paths::storage() . 'model_ids.php');
    }

    /**
     * 获取控制器数据
     *
     * ⚠ `$merge_all = true` 的扁平合并按**短类名**作 key，跨模块同名会互相覆盖 ——
     * 这正是「控制器」统计改为按模块求和的原因（见 `ScaffoldController`）。该臂目前无调用点，
     * 原样保留，见类注释。
     *
     * @throws FileNotFoundException
     */
    public static function controllers(bool $merge_all = true): array
    {
        $data = (new Filesystem)->getRequire(Paths::storage() . 'controllers.php');
        if (! $merge_all) {
            return $data;
        }

        $result = [];
        foreach ($data as $schema_file => $controllers) {
            foreach ($controllers as $class => $attr) {
                $result[$class] = $attr;
            }
        }

        return $result;
    }

    /**
     * 获取字段数据
     *
     * @throws FileNotFoundException
     */
    public static function fields(): array
    {
        return (new Filesystem)->getRequire(Paths::storage() . 'fields.php');
    }

    /**
     * 获取字典数据
     *
     * ⚠ 与 {@see controllers()} 同形：`$merge_all = true` 的扁平合并按**字段名**作 key。
     *
     * @throws FileNotFoundException
     */
    public static function enums(bool $merge_all = true): array
    {
        $enums = (new Filesystem)->getRequire(Paths::storage() . 'enums.php');
        if (! $merge_all) {
            return $enums;
        }

        $result = [];
        foreach ($enums as $table_name => $fields) {
            foreach ($fields as $field_name => $attr) {
                $result[$field_name] = $attr;
            }
        }

        return $result;
    }

    /**
     * 获取字典里的所有词
     *
     * @throws FileNotFoundException
     */
    public static function enumWords(): array
    {
        $enums = self::enums(false);

        $result = [];
        foreach ($enums as $table_name => $fields) {
            foreach ($fields as $field_name => $words) {
                foreach ($words as $alias => $attr) {
                    // 2026-05-21:跳 designer pending sentinel(yaml 占位 __pending_n),
                    // 避免 lang file 出现 memo_status___pending_0 这种 garbage key。
                    // user AI 翻译填好真实 key 后重跑 moo:i18n 才生成 lang 条目。
                    if (str_starts_with((string) $alias, '__pending_')) {
                        continue;
                    }
                    $result[$field_name . '_' . $alias] = ['zh-CN' => $attr[2], 'en' => $attr[1]];
                }
            }
        }

        return $result;
    }

    /**
     * 数据字典统计总数(有字典的模块数 / 枚举字段数 / 字典值数)。
     *
     * 口径跟 ScaffoldController::dictionaries 一致:按模块表分组、只数有枚举的表、
     * value 取原始 case 数 — 供 designer index 字典卡片 + 字典页共用,保证两处数字一致。
     *
     * 缓存文件缺失(没跑 `moo:fresh`)时返回三个 0,不炸首屏。
     */
    public static function dictionaryStats(): array
    {
        try {
            $tables   = self::tables();
            $allEnums = self::enums(false);
        } catch (FileNotFoundException) {
            return ['modules' => 0, 'fields' => 0, 'values' => 0];
        }

        $modules = 0;
        $fields  = 0;
        $values  = 0;

        foreach ($tables as $folder) {
            $moduleHasDict = false;
            foreach (array_keys($folder['tables'] ?? []) as $tableName) {
                $dictionaries = $allEnums[$tableName] ?? [];
                if (empty($dictionaries)) {
                    continue;
                }
                $moduleHasDict = true;
                $fields += count($dictionaries);
                foreach ($dictionaries as $rows) {
                    $values += count($rows);
                }
            }
            if ($moduleHasDict) {
                $modules++;
            }
        }

        return ['modules' => $modules, 'fields' => $fields, 'values' => $values];
    }
}
