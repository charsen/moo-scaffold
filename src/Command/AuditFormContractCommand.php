<?php declare(strict_types=1);
/*
 * @Description: 表单契约审计 —— 断言「create / edit 表单实际渲染出的控件，其 field 必须被对应
 *               Store / Update Request 收下」。原为 wisdomcity 的 `audit:form-contract`，
 *               2026 搬迁进 scaffold 并改名为 `moo:audit:form-contract`：被审计的契约本身
 *               （`FormWidgetCollection` 控件构建 / `FormRequest` 规则投影）完全属于本包，
 *               审计口径就应随契约同源，而不是散落在单个 Host。
 *
 *               为什么需要它：`FormRequest::formatFormConfig` 的末行会把「$base 里配了、但对应
 *               Request rules 里没有」的条目**原样附加**成一个控件。它长得跟正常控件一样
 *               （resource 层会补 field / label），用户点得动，但提交后校验层根本不收 ——
 *               多余键不触发 422，值被静默丢弃，表现为「改了没生效」。
 *
 *               这类缺陷现有工具全测不出：双 smoke 只看状态码；`smoke:form-init` 打 `/{id}/edit`
 *               用假 id → 404 → 响应里压根没有 form_widgets，edit 侧整类不可见；
 *               `audit:controllers` 不比对 $base 与 rules 的交集。
 *
 *               但 rules 之外的键不全等于缺陷：宿主经 `getFormConfig(reset:)` 传进来的「仅用于
 *               表单展示」的附加键（金额回显、keep_department_id 等）本就不该被 rules 收。
 *               这类键现在由 `FormRequest::formatFormConfig` 打 `contract => false` 标记：
 *               - 标记 + 不可见（hidden/disabled/layout 外）= 纯噪音，默认跳过，且要
 *                 `--include-non-contract` / `--all` 才随对应可见性口径计入；
 *               - 标记 + 用户可见 = **仍是真实缺陷**（前端渲染得出、用户填得进、提交被静默丢弃），
 *                 标记只说明它不是 rules 字段，不豁免计数。
 *
 *               还有一类"看着像缺陷、实则刻意"：字段前端渲染控件，但业务上刻意不接收
 *               （rules 被整行注释，例如「早期精简：nullable 字段暂不实现」）。它与真漏写在静态
 *               扫描里同形，靠工具猜必然出错。本命令要求显式声明 —— Request 文件里写机器可读标记：
 *
 *                   // @moo-waived system_logo: 早期精简：nullable 字段暂不实现
 *                   // 'system_logo' => ['nullable', 'string', 'max:192'],
 *
 *               命中的字段归入独立 `waived` 桶：不计违规、不影响退出码；摘要给出条数与原因，
 *               `--include-waived` 展开逐条明细。**未标记**的注释规则仍按可见违规报出；
 *               **陈旧标记**（标记仍在但当期已不构成违规，如规则补回/控件移除）以 warning 报出，
 *               不允许静默忽略 —— 防止豁免机制变成掩盖真漏写的后门。
 *
 *               read-only：不改源码、不发 HTTP、不写业务库（只读地跑控件构建，会查表取 options）。
 *
 *               可见性口径默认全开（= 只报用户可见控件，等价旧的 `--only-visible`）：
 *               `--include-hidden` / `--include-disabled` / `--respect-layout` / `--all` 才把
 *               hidden / disabled / layout 外的噪音纳入统计与退出码。CSV 始终登记全部检出项，
 *               末尾的 Visible / ExcludedBy 两列负责分桶，因此各口径下 CSV 是同一份全集，可直接 diff。
 *               判定收敛在 `Mooeen\Scaffold\Support\FormWidgetVisibility`。
 */

namespace Mooeen\Scaffold\Command;

use Composer\Autoload\ClassLoader;
use Mooeen\Scaffold\Support\FormWidgetVisibility;
use ReflectionClass;

class AuditFormContractCommand extends Command
{
    // 只读体检：任何环境可跑（含 prod 核对），与 moo:db:audit 同口径
    protected bool $requiresLocalEnvironment = false;

    protected string $title = 'Form Contract Audit';

    protected $name = 'moo:audit:form-contract';

    protected $description = 'Assert every rendered create/edit widget field is accepted by the matching Store/Update Request (read-only)';

    protected $signature = 'moo:audit:form-contract
        {--scope=app/Admin/Controllers : Controller root to scan, relative to base_path or absolute}
        {--namespace= : Controller root namespace; auto-derived from --scope when omitted}
        {--module= : Only this module dir, e.g. Finance}
        {--out=storage/app/audit/.audit-form-contract.csv : Output CSV, relative to base_path or absolute}
        {--include-hidden : Also count hidden=true controls (not rendered by the frontend)}
        {--include-disabled : Also count disabled controls}
        {--respect-layout : Only count controls inside formLayout() (default; kept for explicitness)}
        {--include-non-contract : Also count rules-external reset keys marked contract=false}
        {--include-waived : Expand the waived detail list (waived is declared-as-intended, never counted)}
        {--all : Loosen every counting scope (--include-hidden --include-disabled --include-non-contract + ignoring formLayout)}';

    /**
     * 「刻意不实现」的机器可读标记：`// @moo-waived <字段名>: <原因>`。
     *
     * 字段名写在标记里（自包含），注释重构/换行不影响；工具按 Request 文件文本扫描，
     * 不靠"规则被整行注释"的形态特征自动判定 —— 否则会把真漏写一起吞掉。
     */
    private const WAIVED_MARKER_PATTERN = '/@moo-waived\s+([A-Za-z0-9_.\*]+)\s*:\s*(.+)$/m';

    /** @var array<int, array<string, string>> */
    private array $rows = [];

    public function handle(): int
    {
        $this->showTitle();

        // 同一进程内多次 Artisan::call 会复用命令实例，必须清掉上次的 CSV 行，否则重复累积
        $this->rows = [];

        // ⚠ --out 默认落 storage/app/audit/，**不指向 plans/.audit-* baseline** ——
        // 裸跑覆写基线是本项目踩过的坑（见 wisdomcity NOTES.md「smoke:* 的 --out 默认值」条）。
        $outPath = $this->resolveOutPath((string) $this->option('out'));

        $module = trim((string) $this->option('module'));
        $scope  = trim((string) $this->option('scope'));
        $root   = $this->resolveRoot($scope);

        // 可见性口径：默认只报用户可见控件（等价旧的 --only-visible）。
        $all                = (bool) $this->option('all');
        $includeHidden      = $all || (bool) $this->option('include-hidden');
        $includeDisabled    = $all || (bool) $this->option('include-disabled');
        $includeNonContract = $all || (bool) $this->option('include-non-contract');
        // waived 是"已知且接受"的显式声明：无论开什么口径都不计入违规/退出码，
        // --include-waived 只控制是否把每条明细展开（默认摘要已给出条数与原因）。
        $expandWaived = (bool) $this->option('include-waived');
        // 默认即"尊重 layout"；--respect-layout 只是显式声明，--all 才放开 layout 外控件
        $includeLayoutOutside = $all && ! (bool) $this->option('respect-layout');

        $excludeHidden      = ! $includeHidden;
        $excludeDisabled    = ! $includeDisabled;
        $respectLayout      = ! $includeLayoutOutside;
        $excludeNonContract = ! $includeNonContract;

        $namespace = trim((string) $this->option('namespace'));
        if ($namespace === '') {
            $namespace = $this->controllerNamespace($root) ?? '';
        }

        if ($namespace === '') {
            $this->error("无法从 --scope 推导控制器命名空间：{$root}");
            $this->line('  请显式传 --namespace=App\\Admin\\Controllers（或指向 base_path 下的 app/ 子目录）。');

            return self::FAILURE;
        }

        $glob = $module !== ''
            ? $root . '/' . $module . '/*Controller.php'
            : $root . '/*/*Controller.php';

        $files = glob($glob) ?: [];
        if ($files === []) {
            $this->warn("未找到待审计的控制器：{$glob}");
            $this->line('  若该 Host 没有此目录，说明本命令对它无事可做（退出码 0）。');

            return self::SUCCESS;
        }

        $violations  = 0;
        $checked     = 0;
        $skipped     = 0;
        $nonContract = 0;
        $buckets     = ['visible' => 0, 'hidden' => 0, 'disabled' => 0, 'layout' => 0, 'waived' => 0];

        /** @var array<int, array<string, string>> 豁免明细（默认给条数与原因，--include-waived 展开） */
        $waivedDetails = [];

        /** @var array<int, array<string, string>> 陈旧标记：标记了但当期并不构成违规（info/warning） */
        $staleMarkers = [];

        foreach ($files as $file) {
            $short      = basename($file, '.php');
            $stem       = substr($short, 0, -10);
            $moduleName = basename(dirname($file));
            $ctrl       = $namespace . '\\' . $moduleName . '\\' . $short;

            if (! class_exists($ctrl)) {
                continue;
            }

            $rc = new ReflectionClass($ctrl);
            if (! $rc->hasMethod('getFormWidgets')) {
                continue;
            }

            $src = (string) file_get_contents($file);

            foreach ([
                ['create', 'StoreRequest'],
                ['edit', 'UpdateRequest'],
            ] as [$method, $requestClass]) {
                if (! $rc->hasMethod($method)) {
                    continue;
                }

                $request = $this->requestNamespace($namespace) . '\\' . $moduleName . '\\' . $stem . '\\' . $requestClass;
                if (! class_exists($request)) {
                    continue;
                }

                try {
                    $widgets  = $this->renderWidgets($rc, $ctrl, $request, $method);
                    $instance = new $request;

                    // formLayout() 是隐藏变量：非空时 transformLayout 只输出 layout 内的控件，
                    // 规则外「幽灵键」会被整体丢弃 —— 是否命中违规取决于控制器有没有布局。
                    $layout       = method_exists($instance, 'formLayout') ? $instance->formLayout() : [];
                    $layoutFields = FormWidgetVisibility::layoutFields($layout);
                    $hasLayout    = $layoutFields !== [];

                    $allowed = array_keys($instance->rules());
                    $fields  = array_filter(array_column($widgets, 'field'));
                    $extra   = array_values(array_diff($fields, $allowed));
                    $checked++;

                    // 「刻意不实现」的显式声明：扫描 Request 文件里的 `// @moo-waived <field>: <原因>`。
                    // 字段名自包含在标记里，注释重构不会失联；不靠"规则被注释"的形态自动判定。
                    $waived = $this->waivedMarkers($request);

                    // 已知盲区：本命令反射进 `getFormWidgets`，**看不见 controller 层的后处理**。
                    // 典型是 `InOutBudgetController::create()` 紧跟一句 `->forget('budget_personnel_ids')`
                    // 把控件摘掉 —— 真实响应里没有它，报出来就是假阳性。这里按源码里的 forget 调用剔除。
                    $extra = array_values(array_filter(
                        $extra,
                        static fn (string $field): bool => ! str_contains($src, "forget('" . $field . "')")
                    ));

                    // 陈旧标记：标记了某字段，但该字段当期并不构成检出项（规则已补回 / 控件已移除）——
                    // 不能静默忽略，作为 warning 报出来（属维护提醒，不影响退出码）。
                    foreach (array_diff(array_keys($waived), $extra) as $staleField) {
                        $staleMarkers[] = [
                            'module'     => $moduleName,
                            'controller' => $stem,
                            'method'     => $method,
                            'request'    => $requestClass,
                            'field'      => (string) $staleField,
                            'reason'     => $waived[$staleField],
                        ];
                    }

                    // field => 控件（同名字段取第一个），用于可见性判定
                    $byField = [];
                    foreach ($widgets as $widget) {
                        $field = (string) ($widget['field'] ?? '');
                        if ($field !== '' && ! isset($byField[$field])) {
                            $byField[$field] = $widget;
                        }
                    }

                    foreach ($extra as $field) {
                        $widget       = $byField[$field] ?? [];
                        $waivedReason = isset($waived[$field]) ? trim($waived[$field]) : null;

                        // waived 优先级最高：显式声明的字段不参与可见性归因
                        $reason = $waivedReason !== null
                            ? FormWidgetVisibility::REASON_WAIVED
                            : FormWidgetVisibility::exclusionReason($widget, $layoutFields, $hasLayout);
                        $buckets[FormWidgetVisibility::bucketOf($reason)]++;

                        // 开启的口径只影响「违规计数 / 退出码」；CSV 始终登记全部检出项，
                        // 用 Visible / ExcludedBy 分桶 —— 各口径下 CSV 是同一份全集，可直接 diff。
                        $isParticipant = FormWidgetVisibility::isContractParticipant($widget);

                        if ($reason === FormWidgetVisibility::REASON_WAIVED) {
                            // waived = 已知且接受：不计违规、不影响退出码，无论开了哪些口径
                            $waivedDetails[] = [
                                'module'     => $moduleName,
                                'controller' => $stem,
                                'method'     => $method,
                                'field'      => $field,
                                'reason'     => $waivedReason ?? '',
                            ];
                        } elseif (! FormWidgetVisibility::shouldExclude(
                            $widget,
                            $reason,
                            $excludeHidden,
                            $excludeDisabled,
                            $respectLayout,
                            $excludeNonContract,
                        )) {
                            $violations++;
                        } elseif (! $isParticipant) {
                            $nonContract++;
                        }

                        $note = 'widget rendered but not accepted by request rules — user edits are silently dropped';
                        if (! $isParticipant) {
                            // 契约参与项 vs 仅用于 reset/附加值的键：在同一列集内显式区分
                            $note .= ' [contract=false: rules 外 reset 附加键]';
                        }
                        if ($waivedReason !== null) {
                            $note .= ' [waived: ' . str_replace(["\n", ',', ']'], [' ', ';', ')'], $waivedReason) . ']';
                        }

                        $this->rows[] = [
                            'module'     => $moduleName,
                            'controller' => $stem,
                            'method'     => $method,
                            'request'    => $requestClass,
                            'field'      => $field,
                            'widgets'    => (string) count($widgets),
                            'note'       => $note,
                            'visible'    => $reason === null ? 'yes' : 'no',
                            'excludedBy' => (string) $reason,
                        ];
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->rows[] = [
                        'module'     => $moduleName,
                        'controller' => $stem,
                        'method'     => $method,
                        'request'    => $requestClass,
                        'field'      => '',
                        'widgets'    => '',
                        'note'       => 'SKIPPED: ' . str_replace(["\n", ','], [' ', ';'], substr($e->getMessage(), 0, 160)),
                        'visible'    => '',
                        'excludedBy' => '',
                    ];
                }
            }
        }

        $this->writeCsv($outPath);

        $this->newLine();
        $this->line(sprintf('Checked %d form paths, %d skipped.', $checked, $skipped));

        // 摘要始终带分桶：桶是全部检出项的分布（与是否计入违规无关），
        // 默认口径下也能一眼看到被排除的 hidden 噪音 / 显式豁免有多少 —— 不是靠丢弃信息换来的零噪音。
        $this->line(FormWidgetVisibility::summaryLine($violations, $buckets));
        if ($nonContract > 0) {
            $this->line(sprintf(
                '（另有 %d 条 rules 外附加键 contract=false，默认不计违规；用 --include-non-contract 或 --all 查看）',
                $nonContract,
            ));
        }
        if ($waivedDetails !== []) {
            // 「刻意不实现」显式声明：默认给条数与原因；--include-waived 展开到逐条明细。
            // 无论开什么口径都不计入违规、不影响退出码。
            $this->line(sprintf('Waived form fields: %d（显式声明，不计违规%s）', count($waivedDetails), $expandWaived ? '' : '；--include-waived 展开明细'));
            if ($expandWaived) {
                foreach ($waivedDetails as $detail) {
                    $this->line(sprintf(
                        '  - %s/%s.%s %s: %s',
                        $detail['module'],
                        $detail['controller'],
                        $detail['method'],
                        $detail['field'],
                        $detail['reason'],
                    ));
                }
            } else {
                $reasons = [];
                foreach ($waivedDetails as $detail) {
                    $reasons[$detail['field'] . ' = ' . $detail['reason']] = true;
                }
                foreach (array_keys($reasons) as $reason) {
                    $this->line('  - ' . $reason);
                }
            }
        }
        if ($staleMarkers !== []) {
            // 陈旧标记不是后门：标记仍在但已无对应违规（规则补回 / 控件移除）→ warning 提醒清理
            $this->warn(sprintf('Stale waived markers: %d（stale marker；标记仍在，但对应字段当期不构成违规，请清理或修正）', count($staleMarkers)));
            foreach ($staleMarkers as $stale) {
                $this->line(sprintf(
                    '  - [stale marker] %s/%s.%s %s: %s [%s]',
                    $stale['module'],
                    $stale['controller'],
                    $stale['method'],
                    $stale['field'],
                    $stale['reason'],
                    $stale['request'],
                ));
            }
        }
        $this->line('Report: ' . $outPath);

        return $violations === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveOutPath(string $outPath): string
    {
        return str_starts_with($outPath, '/') ? $outPath : base_path($outPath);
    }

    private function resolveRoot(string $scope): string
    {
        if ($scope === '') {
            $scope = 'app/Admin/Controllers';
        }

        if (! str_starts_with($scope, '/')) {
            return rtrim(base_path($scope), '/');
        }

        $real = realpath($scope);

        return rtrim($real === false ? $scope : $real, '/');
    }

    /**
     * 控制器根目录 → 命名空间：优先用宿主 composer 的 PSR-4 前缀反推（app/Admin/Controllers
     * → App\Admin\Controllers），回退到 base_path 相对路径。推导失败返回 null。
     */
    private function controllerNamespace(string $root): ?string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        foreach (spl_autoload_functions() as $fn) {
            if (! is_array($fn) || ! is_object($fn[0]) || ! $fn[0] instanceof ClassLoader) {
                continue;
            }

            $best = null;
            foreach ($fn[0]->getPrefixesPsr4() as $prefix => $dirs) {
                foreach ($dirs as $dir) {
                    $dir = (string) $dir;
                    // composer 前缀里的目录常带 `vendor/composer/../../`，先归一化再比边界
                    $dir = realpath($dir) ?: $dir;
                    $dir = rtrim(str_replace('\\', '/', $dir), '/');
                    if ($dir === '' || $root !== $dir && ! str_starts_with($root, $dir . '/')) {
                        continue;
                    }

                    if ($best === null || strlen($dir) > strlen($best[0])) {
                        $best = [$dir, rtrim($prefix, '\\')];
                    }
                }
            }

            if ($best !== null) {
                $relative  = trim(substr($root, strlen($best[0])), '/');
                $namespace = $best[1];
                if ($relative !== '') {
                    $namespace .= '\\' . str_replace('/', '\\', $relative);
                }

                return $namespace;
            }
        }

        // 回退：base_path 下的相对路径（app/ → App\）
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        if (str_starts_with($root, $base . '/')) {
            $relative  = substr($root, strlen($base) + 1);
            $namespace = '';
            if (str_starts_with($relative, 'app/')) {
                $namespace = 'App\\';
                $relative  = substr($relative, 4);
            }

            return $namespace . str_replace('/', '\\', $relative);
        }

        return null;
    }

    /**
     * 控制器命名空间 → 对应 Request 命名空间（App\Admin\Controllers → App\Admin\Requests）。
     */
    private function requestNamespace(string $namespace): string
    {
        return str_contains($namespace, '\\Controllers')
            ? str_replace('\\Controllers', '\\Requests', $namespace)
            : $namespace . '\\Requests';
    }

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
    private function waivedMarkers(string $requestClass): array
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
     * 反射调用 controller 的 getFormWidgets，拿到序列化后的控件清单（已摊平布局行）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function renderWidgets(ReflectionClass $rc, string $ctrl, string $request, string $method): array
    {
        $controller = app()->make($ctrl);

        $fn = $rc->getMethod('getFormWidgets');
        $fn->setAccessible(true);

        return $this->flatten($fn->invoke($controller, new $request, $method)->toArray(request()));
    }

    /**
     * 响应是「布局行」的二维结构，控件藏在行里 —— 摊到控件粒度。
     *
     * @param array<mixed> $arr
     *
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $arr): array
    {
        $out = [];
        foreach ($arr as $item) {
            $item = is_array($item) ? $item : (array) $item;
            if (array_key_exists('field', $item)) {
                $out[] = $item;

                continue;
            }
            foreach ($this->flatten($item) as $nested) {
                $out[] = $nested;
            }
        }

        return $out;
    }

    private function writeCsv(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fh = fopen($path, 'w');
        fputcsv($fh, ['Module', 'Controller', 'Method', 'Request', 'Field', 'WidgetCount', 'Note', 'Visible', 'ExcludedBy']);
        foreach ($this->rows as $row) {
            fputcsv($fh, array_values($row));
        }
        fclose($fh);
    }
}
