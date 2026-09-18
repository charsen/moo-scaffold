<?php declare(strict_types=1);

/*
 * @Description: 表单控件类型跨仓一致性审计 —— 断言「后端 scaffold 声明的控件类型清单」与
 *   「下游 admin SPA `former/config.ts` 的 elComponents 注册表」是同一个集合。
 *
 * 用法：
 *   php artisan moo:audit:former-types --spa=/path/to/host-frontend
 *   php artisan moo:audit:former-types --spa=/path/to/apps/admin/src/components/former/config.ts
 *   php artisan moo:audit:former-types --spa=... --json
 *
 * 为什么需要它：后端 `FormWidgetTypes::FORMER` 是表单契约**可能下发**的 type 全集（也是
 *   mini-app 等动态类型登记的白名单），前端 `config.ts` 的 elComponents 是把 type 映射到渲染
 *   组件的唯一位置。两侧长期靠 `FormWidgetTypes` 的注释「人工对齐」：漏一边就是「后端下发新
 *   type、前端静默走只读兜底」或「前端注册了后端永不下发的死类型」。plan 61 §2.1 / plan 64 §6.4
 *   要求补上这条自动化跨仓检查，本命令就是那个闸门。
 *
 * 口径（显式，不做模糊匹配）：
 *   - 后端来源：`Mooeen\Scaffold\Support\FormWidgetTypes::FORMER`（运行时类常量，即当前装的包）。
 *   - 前端来源：`former/config.ts` 里 `elComponents` 对象字面量的**顶层键**（`registerWidgets()` 的入参）。
 *   - 只比**集合**，不比顺序（声明顺序不构成渲染语义）。
 *   - 两侧类型名目前逐字相同，故无需别名映射；`ALIASES` 是**显式**映射位（前端名 => 后端名），
 *     命名体系真的分叉时才在此登记，禁止靠大小写 / 连字符之类的模糊归一。
 *
 * 退出码：一致 0 / 不一致 1 / 用法或读取失败 2 —— 「读不到」绝不等于「一致」。
 * 纯只读：不写文件、不跑前端构建、不依赖 DB，任何环境可跑。
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Support\FormWidgetTypes;
use Mooeen\Scaffold\Support\Paths;

class AuditFormerTypesCommand extends Command
{
    /** 只读，任何环境可跑（含生产核对）。 */
    protected bool $requiresLocalEnvironment = false;

    protected string $title = 'Former Widget Type Contract Audit';

    protected $name = 'moo:audit:former-types';

    protected $description = 'Assert the SPA former widget type registry matches the backend FormWidgetTypes::FORMER contract (read-only)';

    protected $signature = 'moo:audit:former-types
        {--spa= : SPA 仓根目录，或 former/config.ts 的绝对/相对路径（必填）}
        {--json : 只输出 JSON（不带横幅），便于脚本消费}';

    /**
     * 前端类型名 => 后端类型名的显式别名。
     *
     * 当前为空：两侧逐字同名（`FormWidgetTypes::FORMER` 的注释即是对齐声明）。将来前端若用与后端
     * 不同的名字注册同一控件，在此登记，命令按别名归一后再比；**不要**改成自动模糊匹配 —— 那会把
     * 真漂移一起吞掉。
     */
    private const ALIASES = [];

    /**
     * SPA 仓根目录下 `former/config.ts` 的约定位置（按顺序探测）。
     *
     * 只覆盖已知的两种仓库形态；落不到时明确报错并提示可直接把 `--spa=` 指到 `config.ts`，
     * 不做全盘递归查找（递归容易撞到别处的同名文件，判定就不可信了）。
     */
    private const SPA_CONFIG_CANDIDATES = [
        'apps/admin/src/components/former/config.ts',   // 多应用 pnpm monorepo（H1 前端仓）
        'src/components/former/config.ts',              // 单应用 Vue 工程
    ];

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->showTitle();
        }

        $raw = trim((string) ($this->option('spa') ?? ''));
        if ($raw === '') {
            return $this->reportError(
                $json,
                'usage',
                '必须用 --spa= 指定 SPA 仓根目录或 former/config.ts 的路径 —— 不给就不知道前端清单，无法判定。',
                ['用法：php artisan moo:audit:former-types --spa=/path/to/host-frontend'],
            );
        }

        // --spa 相对路径按当前工作目录展开（CLI 里 `--spa=../spa` 的自然语义）。
        $path = Paths::absolute($raw, getcwd() ?: base_path());

        if (! file_exists($path)) {
            return $this->reportError(
                $json,
                'spa_path_not_found',
                "SPA 路径不存在：{$path}",
                ['可用 --spa=/abs/path/to/spa 或 --spa=/abs/path/to/former/config.ts。'],
            );
        }

        $configPath = $this->resolveConfigPath($path);
        if ($configPath === null) {
            return $this->reportError(
                $json,
                'config_not_found',
                "在 {$path} 下没找到 former/config.ts（约定位置：" . implode('、', self::SPA_CONFIG_CANDIDATES) . '）。',
                ['可把 --spa= 直接指到 former/config.ts 文件。'],
            );
        }

        $source = @file_get_contents($configPath);
        if ($source === false) {
            return $this->reportError(
                $json,
                'config_unreadable',
                "config.ts 读不到：{$configPath}",
            );
        }

        $frontend = $this->parseElComponentKeys($source);
        if ($frontend === null) {
            return $this->reportError(
                $json,
                'config_unparsable',
                "从 {$configPath} 解析不到 `elComponents` 对象字面量的顶层键 —— 不把它当成空清单，请确认这是 former/config.ts。",
            );
        }

        $backend       = array_values(array_unique(FormWidgetTypes::FORMER));
        $backendRef    = new \ReflectionClass(FormWidgetTypes::class);
        $backendFile   = $backendRef->getFileName() ?: '';
        $backendSymbol = FormWidgetTypes::class . '::FORMER';

        // 别名归一后再比：两侧清单都被视为「规范名集合」。别名会把前端别名折叠成后端名，
        // 因此前端数量可能大于后端而仍然一致 —— 这是显式登记过的命名体系分叉，不是漂移。
        $aliases    = self::ALIASES;
        $normalized = array_values(array_unique(array_map(
            static fn (string $type): string => $aliases[$type] ?? $type,
            $frontend,
        )));

        $frontOnly  = $this->sortedDiff($normalized, $backend);
        $backOnly   = $this->sortedDiff($backend, $normalized);
        $consistent = $frontOnly === [] && $backOnly === [];

        $payload = [
            'consistent' => $consistent,
            'backend'    => [
                'source' => $backendSymbol,
                'file'   => $backendFile,
                'count'  => count($backend),
                'types'  => array_values($backend),
            ],
            'frontend' => [
                'source' => 'elComponents',
                'file'   => $configPath,
                'count'  => count($frontend),
                'types'  => array_values($frontend),
            ],
            'front_only'   => $frontOnly,
            'backend_only' => $backOnly,
            'aliases'      => $aliases,
        ];

        if ($json) {
            $this->console()->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $consistent ? self::SUCCESS : self::FAILURE;
        }

        $this->printReport($payload);

        return $consistent ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 文本报告：两边数量 + 差异项（+ 别名映射表）。
     *
     * @param array{consistent: bool, backend: array{source: string, file: string, count: int, types: list<string>}, frontend: array{source: string, file: string, count: int, types: list<string>}, front_only: list<string>, backend_only: list<string>, aliases: array<string, string>} $payload
     */
    private function printReport(array $payload): void
    {
        $this->console()->table(
            ['侧', '来源', '数量'],
            [
                ['后端', $payload['backend']['source'], (string) $payload['backend']['count']],
                ['前端', $payload['frontend']['file'] . '#' . $payload['frontend']['source'], (string) $payload['frontend']['count']],
            ],
        );
        $this->console()->line('<fg=gray>后端文件：' . ($payload['backend']['file'] !== '' ? $payload['backend']['file'] : '—') . '</>');
        $this->console()->line('');

        if ($payload['aliases'] !== []) {
            $this->console()->line('别名映射（前端名 => 后端名）：');
            $this->console()->table(
                ['前端名', '后端名'],
                array_map(static fn (string $to, string $from): array => [$from, $to], array_values($payload['aliases']), array_keys($payload['aliases'])),
            );
            $this->console()->line('');
        }

        if ($payload['consistent']) {
            $this->console()->success('两侧控件类型清单一致（' . $payload['backend']['count'] . ' 项，集合相同；顺序不构成语义）。');

            return;
        }

        $this->console()->warn(sprintf(
            '✗ 两侧控件类型清单不一致：只在前端 %d 项 / 只在后端 %d 项。',
            count($payload['front_only']),
            count($payload['backend_only']),
        ));

        foreach ($payload['front_only'] as $type) {
            $this->console()->line("  <fg=red>只在前端</> {$type}");
        }
        foreach ($payload['backend_only'] as $type) {
            $this->console()->line("  <fg=yellow>只在后端</> {$type}");
        }

        $this->console()->line('');
        $this->console()->line('<fg=gray>收口：改一边的清单让两侧集合一致 —— 后端改 `Support\\FormWidgetTypes::FORMER`，</>');
        $this->console()->line('<fg=gray>前端改 `former/config.ts` 的 elComponents（新 type 必须在前端注册，否则渲染成只读兜底）。</>');
    }

    /**
     * `--spa` 是文件就用它；是目录就按约定位置探测 `former/config.ts`。
     */
    private function resolveConfigPath(string $path): ?string
    {
        if (is_file($path)) {
            return is_readable($path) ? $path : null;
        }

        if (! is_dir($path)) {
            return null;
        }

        foreach (self::SPA_CONFIG_CANDIDATES as $relative) {
            $candidate = rtrim($path, '/') . '/' . $relative;
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 从 config.ts 源码里取 `elComponents` 对象字面量的顶层键；取不到返回 null
     * （调用方必须报错 —— 解析失败不能退化成「空清单 = 一致」）。
     *
     * @return list<string>|null
     */
    private function parseElComponentKeys(string $source): ?array
    {
        // 锚在声明形态：`elComponents = {` / `elComponents: ElComponentMap = {` /
        // `elComponents = ref<ElComponentMap>({`。后面 `registerWidgets(elComponents.value)`
        // 里的 `elComponents` 不带 `=`，不会命中。
        if (preg_match('/elComponents\s*(?::[^=;{}]+)?=\s*(?:ref\s*(?:<[^>]*>)?\s*\(\s*)?\{/', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $matched = $matches[0][0];
        $brace   = strrpos($matched, '{');
        if ($brace === false) {
            return null;
        }

        $keys = $this->objectKeysAt($source, $matches[0][1] + $brace);

        return $keys === [] ? null : $keys;
    }

    /**
     * 取 `{` 起始的对象字面量的顶层键：只在 depth=1 的 key 位置取，嵌套会 `props` 等键不取。
     *
     * 逐字符扫（先剥注释与字符串，避免它们里面的花括号改深度）：key 只能是裸标识符或引号串；
     * 其它形态（计算键、展开）不识别 —— 前端注册表是字面量，认不出就该报解析失败。
     *
     * @return list<string>
     */
    private function objectKeysAt(string $source, int $open): array
    {
        $length    = strlen($source);
        $depth     = 0;
        $expectKey = false;
        $keys      = [];
        $i         = $open;

        while ($i < $length) {
            $char = $source[$i];

            // 行注释
            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $newline = strpos($source, "\n", $i);
                $i       = $newline === false ? $length : $newline + 1;

                continue;
            }

            // 块注释
            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i   = $end === false ? $length : $end + 2;

                continue;
            }

            // 字符串 / 模板串：整体跳过；只有 depth=1 且正等 key 时才是键名（如 'color-picker':）
            if ($char === "'" || $char === '"' || $char === '`') {
                [$value, $i] = $this->readString($source, $i);
                if ($depth === 1 && $expectKey) {
                    $keys[]    = $value;
                    $expectKey = false;
                }

                continue;
            }

            if ($char === '{') {
                $depth++;
                $expectKey = $depth === 1;
                $i++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                $i++;
                if ($depth <= 0) {
                    break;
                }

                continue;
            }

            if ($char === ',') {
                if ($depth === 1) {
                    $expectKey = true;
                }
                $i++;

                continue;
            }

            // 裸标识符键（如 input:）
            if ($depth === 1 && $expectKey && (ctype_alpha($char) || $char === '_' || $char === '$')) {
                $end = $i;
                while ($end < $length && (ctype_alnum($source[$end]) || $source[$end] === '_' || $source[$end] === '$')) {
                    $end++;
                }
                $keys[]    = substr($source, $i, $end - $i);
                $expectKey = false;
                $i         = $end;

                continue;
            }

            $i++;
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => trim($key) !== '')));
    }

    /**
     * 读一个字符串字面量，返回「去引号内容 + 下一个待扫下标」。
     *
     * @return array{0: string, 1: int}
     */
    private function readString(string $source, int $start): array
    {
        $quote  = $source[$start];
        $length = strlen($source);
        $value  = '';
        $i      = $start + 1;

        while ($i < $length) {
            $char = $source[$i];
            if ($char === '\\') {
                $value .= $source[$i + 1] ?? '';
                $i += 2;

                continue;
            }
            if ($char === $quote) {
                $i++;

                break;
            }
            $value .= $char;
            $i++;
        }

        return [$value, $i];
    }

    /**
     * `array_diff` 保左值顺序、结果可能带洞 —— 这里排序保证输出稳定（CI diff 友好）。
     *
     * @param list<string> $from
     * @param list<string> $against
     *
     * @return list<string>
     */
    private function sortedDiff(array $from, array $against): array
    {
        $diff = array_values(array_diff($from, $against));
        sort($diff);

        return $diff;
    }

    /**
     * 读取 / 用法失败：文本模式打明确错误，JSON 模式只出 JSON；退出码 INVALID(2)，
     * 与「不一致 1」区分开 —— 读不到不等于不一致，也不等于一致。
     *
     * @param list<string> $hints
     */
    private function reportError(bool $json, string $code, string $message, array $hints = []): int
    {
        if ($json) {
            $this->console()->line((string) json_encode([
                'error'   => $code,
                'message' => $message,
                'hints'   => $hints,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::INVALID;
        }

        $this->console()->error($message);
        foreach ($hints as $hint) {
            $this->console()->line("<fg=gray>{$hint}</>");
        }

        return self::INVALID;
    }
}
