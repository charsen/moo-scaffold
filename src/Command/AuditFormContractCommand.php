<?php declare(strict_types=1);
/*
 * @Description: 表单契约审计 —— 断言「create / edit 表单实际渲染出的控件，其 field 必须被对应
 *               Store / Update Request 收下」。原为宿主项目（代号 H1）的 `audit:form-contract`，
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
 *
 *               本文件的结构（2026-09-18 由单个 278 行 handle() 拆开，零行为变化）：
 *               handle() 只做编排 → [解析] 扫描根/命名空间/文件清单 → [口径] 可见性与累加器
 *               → [执行] inspectController → inspectFormPath → recordFindings → [输出] reportFindings。
 *               每段都可单独读：想知道「哪些开关影响计数」看 visibilityScopes() + recordFindings()，
 *               想知道「一行 CSV 怎么来的」看 recordFindings()，想知道「摘要长什么样」看 reportFindings()。
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Support\ControllerScanTarget;
use Mooeen\Scaffold\Support\FormContractMarkers;
use Mooeen\Scaffold\Support\FormWidgetVisibility;
use Mooeen\Scaffold\Support\Paths;
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
     * CSV 行缓冲（writeCsv() 消费）。
     *
     * **寿命 = 一个进程**：本命令**不是**每请求新建 —— 同一进程内多次 `Artisan::call` 复用同一实例，
     * 所以必须在 handle() 开头显式清空，否则上一轮的检出行会接着累。这与控制器正好相反
     * （`RouteController` 由容器逐请求 make，它的请求级 memo 因此不需要清理钩子）。
     * 反过来说：本属性**不能**当跨调用缓存用 —— 「只在第一次 handle() 里构建」那套写法在命令里是错的。
     *
     * @var array<int, array<string, string>>
     */
    private array $rows = [];

    public function handle(): int
    {
        $this->showTitle();

        // per-process 寿命（见 $rows 的注释）：每次 handle() 都要清，否则重复累积
        $this->rows = [];

        // ⚠ --out 默认落 storage/app/audit/，**不指向 plans/.audit-* baseline** ——
        // 裸跑覆写基线是本项目踩过的坑（见 H1 宿主项目 NOTES.md「smoke:* 的 --out 默认值」条）。
        $outPath = Paths::fromBasePath((string) $this->option('out'));
        $root    = ControllerScanTarget::resolveRoot(trim((string) $this->option('scope')));

        // 两个「已就地报错」的解析：null 表示提示已打出来，handle() 只负责翻译成退出码
        $namespace = $this->resolveNamespace($root);
        if ($namespace === null) {
            return self::FAILURE;
        }

        $files = $this->resolveControllerFiles($root, trim((string) $this->option('module')));
        if ($files === null) {
            return self::SUCCESS;
        }

        $scopes = $this->visibilityScopes();
        $totals = $this->emptyTotals();

        foreach ($files as $file) {
            $this->inspectController($file, $namespace, $scopes, $totals);
        }

        $this->writeCsv($outPath);

        $this->console()->newLine();
        $this->console()->line(sprintf('Checked %d form paths, %d skipped.', $totals['checked'], $totals['skipped']));
        $this->reportFindings($totals, $scopes);
        $this->console()->line('Report: ' . $outPath);

        return $totals['violations'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ─── 解析：命名空间 / 文件清单（扫描根推导见 Support\ControllerScanTarget）─────────────

    /**
     * 控制器根目录 → 命名空间：优先 `--namespace`，缺省从扫描根反推（见 ControllerScanTarget::controllerNamespace()）。
     *
     * 推导失败时把提示就地打出来并返回 null，由 handle() 翻成 `self::FAILURE` ——
     * 这样「已经是终态的错误」不必一路往上传值。
     */
    private function resolveNamespace(string $root): ?string
    {
        $namespace = trim((string) $this->option('namespace'));

        if ($namespace === '') {
            $namespace = ControllerScanTarget::controllerNamespace($root) ?? '';
        }

        if ($namespace === '') {
            $this->console()->error("无法从 --scope 推导控制器命名空间：{$root}");
            $this->console()->line('  请显式传 --namespace=App\\Admin\\Controllers（或指向 base_path 下的 app/ 子目录）。');

            return null;
        }

        return $namespace;
    }

    /**
     * 待审计的控制器文件清单：`--module` 限一个模块目录，缺省扫全部子目录。
     *
     * 一个都没找到时提示并返回 null（调用方按**成功**退出）—— 该 Host 没有这个目录本身就是
     * 「本命令对它无事可做」，不是错误。
     *
     * @return list<string>|null
     */
    private function resolveControllerFiles(string $root, string $module): ?array
    {
        $glob = $module !== ''
            ? $root . '/' . $module . '/*Controller.php'
            : $root . '/*/*Controller.php';

        $files = glob($glob) ?: [];

        if ($files === []) {
            $this->console()->warn("未找到待审计的控制器：{$glob}");
            $this->console()->line('  若该 Host 没有此目录，说明本命令对它无事可做（退出码 0）。');

            return null;
        }

        return $files;
    }

    // ─── 口径：可见性开关 + 累加器 ────────────────────────────────────────

    /**
     * 命令行开关 → 各判定开关。默认全部「排除」（= 只报用户可见控件，等价旧的 `--only-visible`）。
     *
     * `--all` 是「全部放开」的总开关；`--respect-layout` 只是**显式声明**（默认本来就尊重 layout），
     * 它的作用是让 `--all` 仍然挡住 layout 外的控件。
     *
     * 注意 `expandWaived` 不参与排除：waived 是「已知且接受」的显式声明，任何口径下都不计入
     * 违规/退出码，`--include-waived` 只控制摘要是否展开逐条明细。
     *
     * @return array{expandWaived: bool, excludeHidden: bool, excludeDisabled: bool, respectLayout: bool, excludeNonContract: bool}
     */
    private function visibilityScopes(): array
    {
        $all = (bool) $this->option('all');

        $includeHidden        = $all || (bool) $this->option('include-hidden');
        $includeDisabled      = $all || (bool) $this->option('include-disabled');
        $includeNonContract   = $all || (bool) $this->option('include-non-contract');
        $includeLayoutOutside = $all && ! (bool) $this->option('respect-layout');

        return [
            'expandWaived'       => (bool) $this->option('include-waived'),
            'excludeHidden'      => ! $includeHidden,
            'excludeDisabled'    => ! $includeDisabled,
            'respectLayout'      => ! $includeLayoutOutside,
            'excludeNonContract' => ! $includeNonContract,
        ];
    }

    /**
     * 检出累加器。**不是**实例状态 —— 它逐控制器累计、随 handle() 结束即丢弃；
     * 与 `$rows` 的 per-process 寿命刻意不同（那个要跨 writeCsv 用、且每次都得清）。
     * 形状只在这里声明一处，inspect* / recordFindings / reportFindings 都按这些键读写。
     *
     * @return array{
     *     violations: int,
     *     checked: int,
     *     skipped: int,
     *     nonContract: int,
     *     buckets: array<string, int>,
     *     waivedDetails: list<array<string, string>>,
     *     staleMarkers: list<array<string, string>>
     * }
     */
    private function emptyTotals(): array
    {
        return [
            'violations'  => 0,
            'checked'     => 0,
            'skipped'     => 0,
            'nonContract' => 0,
            'buckets'     => ['visible' => 0, 'hidden' => 0, 'disabled' => 0, 'layout' => 0, 'waived' => 0],
            // 豁免明细（默认给条数与原因，--include-waived 展开）
            'waivedDetails' => [],
            // 陈旧标记：标记了但当期并不构成违规（info/warning）
            'staleMarkers' => [],
        ];
    }

    // ─── 执行：逐控制器 → 逐表单路径 → 逐字段 ─────────────────────────────

    /**
     * 审一个控制器文件：解析 FQCN → 跑它的两条表单路径（create/StoreRequest、edit/UpdateRequest）。
     *
     * 这里四个「跳过」**不计入 skipped**，因为它们是「这个控制器与本契约无关」而非失败：
     * 类不存在、没有 `getFormWidgets()`、没有 create/edit 方法、对应 Request 类不存在。
     * `skipped` 只有一个来源 —— inspectFormPath() 的 catch。
     *
     * `$totals` 按引用传入：它是就地累计的计数器，比「返回 delta + 调用方合并」少一层样板，
     * 且与「$rows 是实例状态」形成对照，寿命差异一眼可见。
     *
     * @param array<string, bool>  $scopes
     * @param array<string, mixed> $totals
     */
    private function inspectController(string $file, string $namespace, array $scopes, array &$totals): void
    {
        $short      = basename($file, '.php');
        $stem       = substr($short, 0, -10);          // XxxController → Xxx
        $moduleName = basename(dirname($file));
        $ctrl       = $namespace . '\\' . $moduleName . '\\' . $short;

        if (! class_exists($ctrl)) {
            return;
        }

        $rc = new ReflectionClass($ctrl);
        if (! $rc->hasMethod('getFormWidgets')) {
            return;
        }

        // 整个文件读一次：后处理剔除（forget）要看源码文本
        $src = (string) file_get_contents($file);

        foreach ([
            ['create', 'StoreRequest'],
            ['edit', 'UpdateRequest'],
        ] as [$method, $requestClass]) {
            if (! $rc->hasMethod($method)) {
                continue;
            }

            $request = ControllerScanTarget::requestNamespace($namespace) . '\\' . $moduleName . '\\' . $stem . '\\' . $requestClass;
            if (! class_exists($request)) {
                continue;
            }

            $this->inspectFormPath($rc, $ctrl, $src, $request, [
                'module'     => $moduleName,
                'controller' => $stem,
                'method'     => $method,
                'request'    => $requestClass,
            ], $scopes, $totals);
        }
    }

    /**
     * 一条「表单路径」（controller × create/edit）的检出：
     * 渲染控件 → 与 rules 求差 → 剔掉 controller 层 forget 掉的 → 算陈旧标记 → 逐字段落账。
     *
     * 整段包在 try 里：任何一步抛异常都只把这条路径记为 **skipped**（CSV 里留一行 SKIPPED 说明），
     * 不让一个坏控制器打断整轮审计。
     *
     * `$checked` 的口径：在**求差成功之后**才 ++ —— 它是「真的比对过几条表单路径」，
     * 不是「尝试过几次」，所以失败的路径不落在 checked 里、只落在 skipped 里。
     *
     * @param array{module: string, controller: string, method: string, request: string} $where   落在每一行上的身份列
     * @param array<string, bool>                                                        $scopes
     * @param array<string, mixed>                                                       $totals
     * @param string                                                                     $request Request 的 FQCN
     */
    private function inspectFormPath(
        ReflectionClass $rc,
        string $ctrl,
        string $src,
        string $request,
        array $where,
        array $scopes,
        array &$totals,
    ): void {
        try {
            $widgets  = $this->renderWidgets($rc, $ctrl, $request, $where['method']);
            $instance = new $request;

            // formLayout() 是隐藏变量：非空时 transformLayout 只输出 layout 内的控件，
            // 规则外「幽灵键」会被整体丢弃 —— 是否命中违规取决于控制器有没有布局。
            $layout       = method_exists($instance, 'formLayout') ? $instance->formLayout() : [];
            $layoutFields = FormWidgetVisibility::layoutFields($layout);
            $hasLayout    = $layoutFields !== [];

            $allowed = array_keys($instance->rules());
            $fields  = array_filter(array_column($widgets, 'field'));
            $extra   = array_values(array_diff($fields, $allowed));
            $totals['checked']++;

            // 「刻意不实现」的显式声明：扫描 Request 文件里的 `// @moo-waived <field>: <原因>`。
            // 字段名自包含在标记里，注释重构不会失联；不靠"规则被注释"的形态自动判定。
            $waived = FormContractMarkers::waivedMarkers($request);

            $extra = FormContractMarkers::dropForgotten($extra, $src);

            foreach (FormContractMarkers::staleWaivedMarkers($waived, $extra, $where) as $stale) {
                $totals['staleMarkers'][] = $stale;
            }

            $this->recordFindings($widgets, $extra, $waived, $layoutFields, $hasLayout, $where, $scopes, $totals);
        } catch (\Throwable $e) {
            $totals['skipped']++;
            $this->rows[] = $where + [
                'field'      => '',
                'widgets'    => '',
                'note'       => 'SKIPPED: ' . str_replace(["\n", ','], [' ', ';'], substr($e->getMessage(), 0, 160)),
                'visible'    => '',
                'excludedBy' => '',
            ];
        }
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

    // ─── 落账：分桶 / 计数 / CSV 行 ───────────────────────────────────────

    /**
     * 逐字段落账。三条口径都在这里，且都**不随** `--all` / `--include-*` 变：
     *
     *  1. 分桶 `buckets` 是「全部检出项的分布」，所以每个字段都 ++（含被排除的与被豁免的）。
     *     摘要才能一眼看到「被排除的隐藏噪音 / 显式豁免有多少」——不是靠丢弃信息换来的零噪音。
     *  2. waived 优先级最高：显式声明的字段不参与可见性归因，且任何口径下都不计入违规/退出码。
     *  3. CSV 始终登记全部检出项，靠 Visible / ExcludedBy 两列分桶 ⇒ 各口径下 CSV 是同一份全集，
     *     可以直接 diff。**所以本方法不因 `$scopes` 而少写一行**。
     *
     * `$scopes` 只影响 `violations` 这一个计数（进而影响退出码）与 `nonContract` 的归属。
     *
     * @param array<int, array<string, mixed>>                                           $widgets
     * @param list<string>                                                               $extra
     * @param array<string, string>                                                      $waived
     * @param list<string>                                                               $layoutFields
     * @param array{module: string, controller: string, method: string, request: string} $where
     * @param array<string, bool>                                                        $scopes
     * @param array<string, mixed>                                                       $totals
     */
    private function recordFindings(
        array $widgets,
        array $extra,
        array $waived,
        array $layoutFields,
        bool $hasLayout,
        array $where,
        array $scopes,
        array &$totals,
    ): void {
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
            $totals['buckets'][FormWidgetVisibility::bucketOf($reason)]++;

            // 本字段是不是「契约参与项」（区别于宿主仅用于 reset / 展示的附加键）
            $isParticipant = FormWidgetVisibility::isContractParticipant($widget);

            if ($reason === FormWidgetVisibility::REASON_WAIVED) {
                // waived = 已知且接受：不计违规、不影响退出码，无论开了哪些口径
                $totals['waivedDetails'][] = [
                    'module'     => $where['module'],
                    'controller' => $where['controller'],
                    'method'     => $where['method'],
                    'field'      => $field,
                    'reason'     => $waivedReason ?? '',
                ];
            } elseif (! FormWidgetVisibility::shouldExclude(
                $widget,
                $reason,
                $scopes['excludeHidden'],
                $scopes['excludeDisabled'],
                $scopes['respectLayout'],
                $scopes['excludeNonContract'],
            )) {
                $totals['violations']++;
            } elseif (! $isParticipant) {
                $totals['nonContract']++;
            }

            $note = 'widget rendered but not accepted by request rules — user edits are silently dropped';
            if (! $isParticipant) {
                // 契约参与项 vs 仅用于 reset/附加值的键：在同一列集内显式区分
                $note .= ' [contract=false: rules 外 reset 附加键]';
            }
            if ($waivedReason !== null) {
                $note .= ' [waived: ' . str_replace(["\n", ',', ']'], [' ', ';', ')'], $waivedReason) . ']';
            }

            $this->rows[] = $where + [
                'field'      => $field,
                'widgets'    => (string) count($widgets),
                'note'       => $note,
                'visible'    => $reason === null ? 'yes' : 'no',
                'excludedBy' => (string) $reason,
            ];
        }
    }

    // ─── 输出：摘要 + CSV ────────────────────────────────────────────────

    /**
     * 摘要：分桶行 → rules 外附加键 → waived → 陈旧标记。
     *
     * 摘要**始终**带分桶，与「是否计入违规」无关；后面几块只在有内容时出现，
     * 顺序固定（先 waived 明细、后 stale 警告 —— stale 是「该清了」的维护提醒，放最后更醒目）。
     *
     * @param array<string, mixed> $totals
     * @param array<string, bool>  $scopes
     */
    private function reportFindings(array $totals, array $scopes): void
    {
        $this->console()->line(FormWidgetVisibility::summaryLine($totals['violations'], $totals['buckets']));

        if ($totals['nonContract'] > 0) {
            $this->console()->line(sprintf(
                '（另有 %d 条 rules 外附加键 contract=false，默认不计违规；用 --include-non-contract 或 --all 查看）',
                $totals['nonContract'],
            ));
        }

        if ($totals['waivedDetails'] !== []) {
            // 「刻意不实现」显式声明：默认给条数与原因；--include-waived 展开到逐条明细。
            // 无论开什么口径都不计入违规、不影响退出码。
            $this->console()->line(sprintf(
                'Waived form fields: %d（显式声明，不计违规%s）',
                count($totals['waivedDetails']),
                $scopes['expandWaived'] ? '' : '；--include-waived 展开明细',
            ));

            if ($scopes['expandWaived']) {
                foreach ($totals['waivedDetails'] as $detail) {
                    $this->console()->line(sprintf(
                        '  - %s/%s.%s %s: %s',
                        $detail['module'],
                        $detail['controller'],
                        $detail['method'],
                        $detail['field'],
                        $detail['reason'],
                    ));
                }
            } else {
                // 未展开时按「字段 = 原因」去重：多个模块同一原因不必重复播
                $reasons = [];
                foreach ($totals['waivedDetails'] as $detail) {
                    $reasons[$detail['field'] . ' = ' . $detail['reason']] = true;
                }
                foreach (array_keys($reasons) as $reason) {
                    $this->console()->line('  - ' . $reason);
                }
            }
        }

        if ($totals['staleMarkers'] !== []) {
            // 陈旧标记不是后门：标记仍在但已无对应违规（规则补回 / 控件移除）→ warning 提醒清理
            $this->console()->warn(sprintf(
                'Stale waived markers: %d（stale marker；标记仍在，但对应字段当期不构成违规，请清理或修正）',
                count($totals['staleMarkers']),
            ));
            foreach ($totals['staleMarkers'] as $stale) {
                $this->console()->line(sprintf(
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
    }

    /**
     * 写出 CSV。列集固定（在测试里锁死），行内容见 $rows 的构造处。
     */
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
