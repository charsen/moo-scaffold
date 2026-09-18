<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table as SymfonyTable;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * 命令行输出的**唯一出口**。
 *
 * 两层职责，边界固定：
 *   - 语义级输出（title / section / info / success / warn / error / status* / detail）：本类决定「长什么样」；
 *   - 原样输出（line / newLine / table）：只路由，不加工。
 *
 * 命令 / 生成器一律用 `$this->console()->…` 输出，不要绕过本类直连 `$this->line()` / `$this->output->writeln()`。
 */
class ConsoleUi
{
    private const STATUS_STYLES = [
        'created'     => ['icon' => '✨', 'style' => 'fg=green;options=bold'],
        'updated'     => ['icon' => '🔄', 'style' => 'fg=blue;options=bold'],
        'overwritten' => ['icon' => '🔁', 'style' => 'fg=cyan;options=bold'],
        'added'       => ['icon' => '➕', 'style' => 'fg=green;options=bold'],
        'appended'    => ['icon' => '📎', 'style' => 'fg=cyan;options=bold'],
        'skipped'     => ['icon' => '⏭️', 'style' => 'fg=yellow;options=bold'],
        'exists'      => ['icon' => '💤', 'style' => 'fg=yellow;options=bold'],
        'failed'      => ['icon' => '🔴', 'style' => 'fg=red;options=bold'],
        'unchanged'   => ['icon' => '➖', 'style' => 'fg=gray;options=bold'],
        'parsed'      => ['icon' => '🔍', 'style' => 'fg=magenta;options=bold'],
        'history'     => ['icon' => '📋', 'style' => 'fg=magenta;options=bold'],
        'cleaned'     => ['icon' => '🗑️', 'style' => 'fg=blue;options=bold'],
        'ready'       => ['icon' => '🌟', 'style' => 'fg=green;options=bold'],
    ];

    public function __construct(
        private readonly ConsoleCommand|Factory|OutputInterface $target,
    ) {}

    public function title(string $title): void
    {
        $this->emit('alert', '🚀 ' . $this->clean($title));
    }

    public function section(string $label): void
    {
        $this->emit('warn', '🎯 ' . $this->clean($label));
    }

    public function prompt(string $question): string
    {
        return '💬 ' . $this->clean($question);
    }

    public function info(string $message): void
    {
        $this->emit('info', 'ℹ️  ' . $this->stripMarker($message, 'ℹ️', 'ℹ'));
    }

    public function success(string $message): void
    {
        $this->emit('success', '✅ ' . $this->stripMarker($message, '✅'));
    }

    public function warn(string $message): void
    {
        $this->emit('warn', '⚠️  ' . $this->stripMarker($message, '⚠️', '⚠'));
    }

    public function error(string $message): void
    {
        $this->emit('error', '❌ ' . $this->stripMarker($message, '❌'));
    }

    public function newLine(int $count = 1): void
    {
        if ($this->target instanceof ConsoleCommand) {
            $this->target->newLine($count);

            return;
        }

        $this->resolveOutput()->write(str_repeat(PHP_EOL, $count));
    }

    // -------------------------------------------------------------------------
    // 原样输出透传 —— 只做「出口收口」，不做任何加工
    //
    // 上面那组（title / section / info / success / warn / error / status / detail）是**语义级**输出，
    // 由本类决定「长什么样」（emoji 前缀、两列对齐等）；line() / newLine() / table() 是**原样**输出，
    // 本类只负责把它们路由到目标，不改变内容。
    //
    // 它们存在的意义不是加工，而是让命令层只有一个输出出口：全部走 `$this->console()`，
    // 不再混用 `$this->line()` / `$this->output->writeln()` 这类绕过本类的写法 —— 否则「谁来渲染」
    // 就有两个答案，改一处口径必漏另一处。
    // -------------------------------------------------------------------------

    /**
     * 原样输出一行。语义同 `Illuminate\Console\Command::line()`：`$style` 为 null 时不加包装。
     */
    public function line(string $string, ?string $style = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        if ($this->target instanceof ConsoleCommand) {
            $this->target->line($string, $style, $verbosity);

            return;
        }

        // Factory / OutputInterface 目标：直接写底层输出，不套组件包装 ——
        // 注意 `Factory::line()` 的参数顺序是 (style, string)，与 Command 相反，且套一层 `<info>`
        // 会让字符串里自带的 `</>` 提前把样式弹回外层，故不转发给组件。
        //
        // `$verbosity` 放的是 `writeln()` 的 `$options` 槽 —— 这不是笔误：Symfony 的
        // `Output::write()` 会用 verbosities 位掩码从 `$options` 里取 verbosity，
        // Laravel 的 `Command::line()` 也是这么传的。
        $this->resolveOutput()->writeln($style !== null ? "<{$style}>{$string}</{$style}>" : $string, $verbosity);
    }

    /**
     * 输出表格。Command 目标走框架实现（支持自定义 TableStyle）；其余目标用 Symfony 默认样式兜底
     * （Console 组件目录里没有 Table 组件，无法经 Factory 转发）。
     *
     * @param array<int, string>                      $headers
     * @param array<int, array<int, mixed>>|Arrayable $rows
     */
    public function table(array $headers, array|Arrayable $rows, string $style = 'default'): void
    {
        if ($this->target instanceof ConsoleCommand) {
            $this->target->table($headers, $rows, $style);

            return;
        }

        (new SymfonyTable($this->resolveOutput()))
            ->setHeaders($headers)
            ->setRows($rows instanceof Arrayable ? $rows->toArray() : $rows)
            ->render();
    }

    public function created(string $subject, string $detail = 'Created'): void
    {
        $this->status('created', $subject, $detail);
    }

    public function updated(string $subject, string $detail = 'Updated'): void
    {
        $this->status('updated', $subject, $detail);
    }

    public function overwritten(string $subject): void
    {
        $this->status('overwritten', $subject, 'Overwritten');
    }

    public function added(string $subject, string $detail = 'Added'): void
    {
        $this->status('added', $subject, $detail);
    }

    public function appended(string $subject, string $detail = 'Appended'): void
    {
        $this->status('appended', $subject, $detail);
    }

    public function skipped(string $subject, string $detail = 'Skipped'): void
    {
        $this->status('skipped', $subject, $detail);
    }

    public function exists(string $subject, string $detail = 'Already exists'): void
    {
        $this->status('exists', $subject, $detail);
    }

    public function failed(string $subject, string $detail = 'Failed'): void
    {
        $this->status('failed', $subject, $detail);
    }

    public function unchanged(string $subject, string $detail = 'No changes'): void
    {
        $this->status('unchanged', $subject, $detail);
    }

    public function parsed(string $subject, string $detail = 'Parsed'): void
    {
        $this->status('parsed', $subject, $detail);
    }

    public function history(string $subject, string $detail = 'History'): void
    {
        $this->status('history', $subject, $detail);
    }

    public function cleaned(string $subject, string $detail = 'Cleaned'): void
    {
        $this->status('cleaned', $subject, $detail);
    }

    public function ready(string $subject, string $detail = 'Ready'): void
    {
        $this->status('ready', $subject, $detail);
    }

    public function detail(string $subject, string $detail): void
    {
        $this->renderTwoColumnLine(
            $this->clean($subject),
            $this->clean($detail),
        );
    }

    private function status(string $status, string $subject, string $detail): void
    {
        $config  = self::STATUS_STYLES[$status] ?? ['icon' => '•', 'style' => 'fg=white'];
        $subject = $this->clean($subject);
        $detail  = $this->clean($detail);

        $left = $this->wrap(
            $config['style'],
            $config['icon']
        ) . ' ' . $this->wrap('fg=gray', $subject);

        $right = $this->wrap($config['style'], $detail);

        $this->renderTwoColumnLine($left, $right, $this->visibleWidth($config['icon'] . ' ' . $subject), $this->visibleWidth($detail));
    }

    private function emit(string $style, string $message): void
    {
        if ($this->target instanceof ConsoleCommand) {
            if (in_array($style, ['alert', 'success'], true)) {
                $this->factory()->{$style}($message);

                return;
            }

            $this->target->{$style}($message);

            return;
        }

        if ($this->target instanceof Factory) {
            $this->target->{$style}($message);

            return;
        }

        $tag = match ($style) {
            'error'         => 'error',
            'warn', 'alert' => 'comment',
            default         => 'info',
        };

        $this->target->writeln("<{$tag}>{$message}</{$tag}>");
    }

    private function clean(string $value): string
    {
        return trim($value);
    }

    /**
     * 去掉消息**自带的本级前导标记**，保证语义级输出的标记恰好一个。
     *
     * 场景：消息来自别处、而那个来源按契约就带标记 —— 例如 `SnapshotStore::baselineNote()`
     * 约定返回 `'⚠ …'`（web 端也直接展示，测试锁着 `toStartWith('⚠ ')`），命令层再经 `warn()`
     * 就会渲染成 `⚠️  ⚠ …`。标记由本类统一给出，所以在这里去重，而不是去改那些消息。
     *
     * 只认**前导**且**属于本级**的标记：`warn('重启前确认 ⚠ 这一条')` 里非前导的 ⚠、
     * 以及 `info('✓ 通过')` 里的 ✓（那是作者有意表达「通过」，不是 info 的标记）都不动。
     */
    private function stripMarker(string $message, string ...$markers): string
    {
        $message = $this->clean($message);

        foreach ($markers as $marker) {
            if (str_starts_with($message, $marker)) {
                return ltrim(mb_substr($message, mb_strlen($marker)), " \t");
            }
        }

        return $message;
    }

    private function renderTwoColumnLine(string $left, string $right, ?int $leftWidth = null, ?int $rightWidth = null): void
    {
        $leftWidth  ??= $this->visibleWidth($left);
        $rightWidth ??= $this->visibleWidth($right);

        $terminalWidth = $this->terminalWidth();
        $contentWidth  = max(40, min(150, $terminalWidth - 4));
        $leaderWidth   = max(2, $contentWidth - $leftWidth - $rightWidth - 2);
        $leader        = $this->wrap('fg=gray', str_repeat('.', $leaderWidth));

        $this->resolveOutput()->writeln("  {$left} {$leader} {$right}");
    }

    private function wrap(string $style, string $value): string
    {
        return "<{$style}>" . OutputFormatter::escape($value) . '</>';
    }

    private function visibleWidth(string $value): int
    {
        $plain = preg_replace('/<[^>]+>/', '', $value) ?? $value;

        if (function_exists('mb_strwidth')) {
            return mb_strwidth($plain, 'UTF-8');
        }

        return strlen($plain);
    }

    private function terminalWidth(): int
    {
        return (new Terminal)->getWidth();
    }

    private function factory(): Factory
    {
        if ($this->target instanceof Factory) {
            return $this->target;
        }

        if ($this->target instanceof ConsoleCommand) {
            return new Factory($this->target->getOutput());
        }

        return new Factory($this->target);
    }

    private function resolveOutput(): OutputInterface
    {
        if ($this->target instanceof ConsoleCommand) {
            return $this->target->getOutput();
        }

        if ($this->target instanceof Factory) {
            static $prop;
            $prop ??= new \ReflectionProperty(Factory::class, 'output');

            return $prop->getValue($this->target);
        }

        return $this->target;
    }
}
