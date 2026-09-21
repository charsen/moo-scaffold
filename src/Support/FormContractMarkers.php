<?php declare(strict_types=1);
/*
 * @Description: 表单契约审计的「豁免标记」处理 —— Request 源码里显式声明的
 *               `// @moo-waived <字段名>: <原因>` 的解析 / 剔除 / 陈旧检测。
 *
 *               2026-09-20 从 `Command\AuditFormContractCommand` 外迁（第 9 项）。原为它的
 *               3 个私有方法（`waivedMarkers` / `dropForgotten` / `staleWaivedMarkers`）外加一个
 *               私有常量，搬迁是**纯搬家**：方法体逐字节未动，只把可见性从「命令的私有实例方法」
 *               改成「独立类的公开静态方法」。
 *
 *               为什么能搬（判据三层）：① 族外调用点恰好 3 个、都挤在 `inspectFormPath()` 的同一条
 *               流水线上（解析 → 剔除 → 陈旧检测）；② 族内内聚 —— 三个方法合起来就是「豁免标记」
 *               这一条职责；③ 零状态 —— 都不碰 `$this`，也不碰容器 / facade / `config()`。
 *               因此新家是 `final` + 全 `static` + 零属性 + 零构造函数（唯一 import 是 `ReflectionClass`，
 *               用于从 Request 类反查其源文件）。
 *
 *               三者的关系（原样保留的语义）：
 *               · `waivedMarkers(Request 类名)` → `字段名 => 原因`；标记**自包含**字段名，注释重排不失联；
 *               · `dropForgotten(list, 控制器源码)` → 剔除控制器层 `->forget('<field>')` 掉的字段
 *                 （反射看不见 controller 层后处理，只能按源码字面近似）；
 *               · `staleWaivedMarkers(waived, extra, where)` → 标记了却当期不构成检出的「陈旧标记」，
 *                 报 warning 不静默忽略 —— 防止豁免机制退化成掩盖真漏写的后门。
 */

namespace Mooeen\Scaffold\Support;

use ReflectionClass;

final class FormContractMarkers
{
    /**
     * 「刻意不实现」的机器可读标记：`// @moo-waived <字段名>: <原因>`。
     *
     * 字段名写在标记里（自包含），注释重构/换行不影响；工具按 Request 文件文本扫描，
     * 不靠"规则被整行注释"的形态特征自动判定 —— 否则会把真漏写一起吞掉。
     */
    private const WAIVED_MARKER_PATTERN = '/@moo-waived\s+([A-Za-z0-9_.\*]+)\s*:\s*(.+)$/m';

    /**
     * 扫描 Request 类文件里的「刻意不实现」标记，返回 字段名 => 原因。
     *
     * 标记语法（固定在命令文档与测试里）：
     *   // @moo-waived system_logo: 早期精简：nullable 字段暂不实现
     *   // 'system_logo' => ['nullable', 'string', 'max:192'],
     *
     * 字段名必须写在标记里（自包含），不靠与被注释规则相邻或形态推断 —— 注释重排不会失效，
     * 也不会把"真漏写"自动吞掉。
     *
     * @return array<string, string>
     */
    public static function waivedMarkers(string $requestClass): array
    {
        $file = (new ReflectionClass($requestClass))->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }

        $src = (string) file_get_contents($file);
        if (preg_match_all(self::WAIVED_MARKER_PATTERN, $src, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $markers = [];
        foreach ($matches as $match) {
            $markers[$match[1]] = trim($match[2]);
        }

        return $markers;
    }

    /**
     * 剔除 controller 层 `->forget('<field>')` 掉的字段。
     *
     * 已知盲区：本命令反射进 `getFormWidgets`，**看不见 controller 层的后处理**。典型是
     * `InOutBudgetController::create()` 紧跟一句 `->forget('budget_personnel_ids')` 把控件摘掉
     * —— 真实响应里没有它，报出来就是假阳性。这里按源码里的 forget 调用剔除。
     *
     * 精度边界：按 `forget('field')` 字面匹配整份控制器源码，所以**同名字段**在别的 action 里
     * 被 forget 也会连带剔除。宁可漏报也不误报（假阳性的代价更高 —— 会让人不再信任这个审计）。
     *
     * @param list<string> $extra
     *
     * @return list<string>
     */
    public static function dropForgotten(array $extra, string $src): array
    {
        return array_values(array_filter(
            $extra,
            static fn (string $field): bool => ! str_contains($src, "forget('" . $field . "')")
        ));
    }

    /**
     * 陈旧标记：标记了某字段，但该字段当期并不构成检出项（规则已补回 / 控件已移除）——
     * 不能静默忽略，作为 warning 报出来（属维护提醒，不影响退出码）。
     *
     * @param array<string, string>                                                      $waived
     * @param list<string>                                                               $extra
     * @param array{module: string, controller: string, method: string, request: string} $where
     *
     * @return list<array<string, string>>
     */
    public static function staleWaivedMarkers(array $waived, array $extra, array $where): array
    {
        $stale = [];

        foreach (array_diff(array_keys($waived), $extra) as $field) {
            $stale[] = $where + ['field' => (string) $field, 'reason' => $waived[$field]];
        }

        return $stale;
    }
}
