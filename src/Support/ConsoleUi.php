<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

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
        $this->emit('info', 'ℹ️  ' . $this->clean($message));
    }

    public function success(string $message): void
    {
        $this->emit('success', '✅ ' . $this->clean($message));
    }

    public function warn(string $message): void
    {
        $this->emit('warn', '⚠️  ' . $this->clean($message));
    }

    public function error(string $message): void
    {
        $this->emit('error', '❌ ' . $this->clean($message));
    }

    public function newLine(int $count = 1): void
    {
        if ($this->target instanceof ConsoleCommand) {
            $this->target->newLine($count);

            return;
        }

        $this->resolveOutput()->write(str_repeat(PHP_EOL, $count));
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
