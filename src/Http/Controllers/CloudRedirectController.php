<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * 本地「运行时错误 / 慢 SQL / Todos」查看器已退役 —— 统一在 moo-scaffold-cloud 查看。
 *
 * 保留原路由名 + 原 URL 路径为「重定向桩」:仪表板 / designer / 钉钉通知里既有的
 * route('runtime.show') 等调用与生成的 URL 字符串全部不变,只是访问时跳云端,不 404。
 * 捕获链路(RuntimeErrorRecorder / SqlSlowListener 落盘 + moo:cloud:push 上云)不受影响。
 */
class CloudRedirectController extends Controller
{
    public function runtimes(): Response
    {
        return $this->toCloud('运行时错误');
    }

    public function slowQueries(): Response
    {
        return $this->toCloud('慢 SQL');
    }

    private function toCloud(string $label): Response
    {
        $cloud = rtrim((string) config('moo-monitor.cloud.base_url', ''), '/');
        if ($cloud !== '') {
            return redirect()->away($cloud . '/app');
        }

        return response($this->notice($label))->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function notice(string $label): string
    {
        return '<!doctype html><meta charset="utf-8"><title>已迁移到云端</title>'
            . '<div style="max-width:560px;margin:18vh auto;font:15px/1.7 system-ui,-apple-system,sans-serif;color:#333;padding:0 24px">'
            . '<h1 style="font-size:20px;margin:0 0 .6em">「' . e($label) . '」本地查看器已退役</h1>'
            . '<p>运行时错误、慢 SQL、Todos 已统一到 <strong>moo-scaffold-cloud</strong> 查看。'
            . '云端地址默认 <code>https://c.mooeen.com</code>（本页出现说明它被显式清空了，'
            . '恢复 <code>MOO_MONITOR_CLOUD_URL</code> 或删掉该 env 即自动跳转云端）。</p>'
            . '<p style="color:#888">本地仅保留临时采集缓冲，由 <code>moo:cloud:push</code> 推送上云。</p>'
            . '</div>';
    }
}
