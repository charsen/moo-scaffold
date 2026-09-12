<?php declare(strict_types=1);
/*
 * @Description: 表单契约审计的「可见性口径」判定 —— 把「用户可见的真实违规」与「契约噪音」分桶。
 *               纯函数、不依赖框架与宿主，便于单测与跨 Host 复用。
 *
 *               三个可见性口径（新命令默认全部开启，即"只报用户可见控件"）：
 *               - hidden   : `hidden => true` 的控件。前端不渲染 hidden 控件，
 *                            谈不上「用户编辑被静默丢弃」，属误报；
 *               - disabled : `disabled` 控件。注意：前端 disabled 控件仍可能提交，
 *                            它不能代替服务端不可变守卫，只是不计入本命令「编辑被丢」这一口径；
 *               - layout   : Request 定义了 formLayout() 时，layout 之外的控件会被
 *                            `FormWidgetCollection::transformLayout()` 整体丢弃，真实响应里没有它。
 *
 *               另一维是「契约参与项」与「仅用于 reset/附加值的键」：
 *               `FormRequest::formatFormConfig()` 的末行会把 rules 之外、宿主经
 *               `getFormConfig(reset:)` 传入的键原样附加成控件（典型：金额回显、keep_department_id
 *               之类的 hidden 辅助键，也有 Property 表单那种可见的 cascader）。这些键不是本
 *               Request 的契约字段，校验层刻意不收，被打上 `contract => false` 标记，
 *               `isContractParticipant()` 据此与真正的契约字段分开。
 *               计数口径分两种：
 *               - 标记 + 不可见（hidden/disabled/layout 外）：纯噪音，默认跳过，
 *                 且要 `--include-non-contract`（或 `--all`）才随对应可见性口径一起计入；
 *               - 标记 + 用户可见：**仍是真实缺陷**。前端渲染得出来、用户填得进去，提交后值被
 *                 静默丢弃，与 rules 内字段被丢没有区别 —— 标记只用于说明"它不是 rules 字段"，
 *                 不豁免它的可见违规。
 *
 *               参考：moo-scaffold `FormRequest::checkFormLayout()` 与
 *               `FormWidgetCollection::transformLayout()` 的取字段口径（两者必须一致，否则会把
 *               真实渲染出来的控件误判成 layout 外）。
 */

namespace Mooeen\Scaffold\Support;

final class FormWidgetVisibility
{
    /** 排除口径：hidden 控件 */
    public const REASON_HIDDEN = 'hidden';

    /** 排除口径：disabled 控件 */
    public const REASON_DISABLED = 'disabled';

    /** 排除口径：不在 formLayout() 内（transformLayout 会丢弃） */
    public const REASON_LAYOUT = 'layout';

    /** 排除口径：Request 文件里用 `// @moo-waived <field>: <原因>` 标记的「刻意不实现」字段 */
    public const REASON_WAIVED = 'waived';

    /** 契约标记键：`contract => false` 表示该控件不是本 Request 的契约字段 */
    public const CONTRACT_KEY = 'contract';

    /**
     * 从 `formLayout()` 的原始结构提取字段名集合。
     *
     * 口径同 moo-scaffold：每行要么是 `['field']`（下标数字、值字符串），要么是
     * `['field' => config]`（字段名作键），也兼容一行多个控件的混排。
     *
     * @param array<mixed> $layout
     *
     * @return array<int, string>
     */
    public static function layoutFields(array $layout): array
    {
        $fields = [];
        foreach ($layout as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $key => $value) {
                $field = is_int($key) ? (is_string($value) ? $value : null) : (string) $key;
                if ($field !== null && $field !== '') {
                    $fields[] = $field;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * 该控件是否是 Request 契约的参与项。
     *
     * `FormRequest::formatFormConfig()` 给 rules 外附加的 reset 键打 `contract => false`；
     * 其余控件（含宿主显式写了 `contract => true` 或没写该键的）都视为契约参与项。
     *
     * @param array<string, mixed> $widget 摊平后的单个控件
     */
    public static function isContractParticipant(array $widget): bool
    {
        return ! (array_key_exists(self::CONTRACT_KEY, $widget) && $widget[self::CONTRACT_KEY] === false);
    }

    /**
     * 控件被哪个可见性口径排除；null 表示用户可见的真实控件。
     *
     * 优先级 hidden > disabled > layout：同一控件只归一个桶，避免分桶重复计数。
     * 「契约外附加键」不在此处判定 —— 它是独立维度，由 isContractParticipant() 负责。
     *
     * @param array<string, mixed> $widget       摊平后的单个控件
     * @param array<int, string>   $layoutFields layoutFields() 的结果
     * @param bool                 $hasLayout    对应 Request 是否定义了非空 formLayout()
     */
    public static function exclusionReason(array $widget, array $layoutFields, bool $hasLayout): ?string
    {
        if (! empty($widget['hidden'])) {
            return self::REASON_HIDDEN;
        }

        if (! empty($widget['disabled'])) {
            return self::REASON_DISABLED;
        }

        if ($hasLayout && ! in_array((string) ($widget['field'] ?? ''), $layoutFields, true)) {
            return self::REASON_LAYOUT;
        }

        return null;
    }

    /**
     * 该控件是否因本次运行开启的口径而从「违规计数」中剔除。
     *
     * 口径优先级：
     * 1. 用户可见（$reason === null）：永远计入 —— 可见的 rules 外附加键也是真实缺陷
     *    （用户可编辑、提交被静默丢弃），contract 标记只作说明，不豁免；
     * 2. 不可见（hidden/disabled/layout 外）：先看契约口径 —— 带 `contract => false` 的
     *    附加键默认纯噪音，需 $excludeNonContract === false 才继续；再看对应可见性口径；
     * 3. 任一处于"关闭"状态即剔除。默认（只报可见）四项全剔除。
     *
     * 注意：仅影响计数与退出码；CSV 仍登记全部检出项，靠 Visible / ExcludedBy 两列分桶。
     */
    public static function shouldExclude(
        array $widget,
        ?string $reason,
        bool $excludeHidden,
        bool $excludeDisabled,
        bool $respectLayout,
        bool $excludeNonContract,
    ): bool {
        if ($reason === null) {
            return false;
        }

        if ($excludeNonContract && ! self::isContractParticipant($widget)) {
            return true;
        }

        return match ($reason) {
            self::REASON_HIDDEN   => $excludeHidden,
            self::REASON_DISABLED => $excludeDisabled,
            self::REASON_LAYOUT   => $respectLayout,
            default               => false,
        };
    }

    /**
     * 分桶计数用的桶名（visible 桶由调用方在 reason 为 null 时使用）。
     */
    public static function bucketOf(?string $reason): string
    {
        return $reason ?? 'visible';
    }

    /**
     * 结尾的违规摘要行，始终带分桶明细。
     *
     * 桶 = 全部检出项的分布（与是否计入违规无关），因此默认口径下也能一眼看到
     * 「排除掉的 hidden 噪音 / 显式豁免有多少」—— 避免"靠丢弃信息"换来的零噪音。
     *
     * @param array<string, int> $buckets 桶名 => 检出数（含 visible / waived）
     */
    public static function summaryLine(int $violations, array $buckets): string
    {
        return sprintf(
            'Contract violations: %d (visible %d, hidden %d, disabled %d, layout-only %d, waived %d)',
            $violations,
            $buckets['visible']  ?? 0,
            $buckets['hidden']   ?? 0,
            $buckets['disabled'] ?? 0,
            $buckets['layout']   ?? 0,
            $buckets['waived']   ?? 0,
        );
    }
}
