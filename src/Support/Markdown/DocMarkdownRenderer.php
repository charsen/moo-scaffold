<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * plan-52:文档 Markdown → HTML 渲染器。
 *
 *   - league/commonmark + GFM（表格 / 删除线 / 任务列表 / 自动链接）。
 *   - html_input=escape + allow_unsafe_links=false：关原始 HTML（全站 CSP，正文内联 script/style
 *     既被拦又是 XSS 口子）。动态能力只走 shortcode 这一个授权出口（DocShortcodeExtension）。
 *   - ```mermaid 围栏块后处理成隔离 iframe：源放进 hidden <pre>，前端 JS 读出经 postMessage 喂给
 *     /scaffold/docs/_diagram 帧渲染（帧单独放宽 CSP，见 SecurityHeaders）。保存态与实时预览同一条路径。
 */
final class DocMarkdownRenderer
{
    public function __construct(private readonly DocShortcodeResolver $resolver) {}

    public function render(string $markdown): string
    {
        $environment = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'renderer'           => ['soft_break' => "\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new DocShortcodeExtension($this->resolver));

        $html = (string) (new MarkdownConverter($environment))->convert($this->escapeShortcodePipes($markdown));

        return $this->transformMermaid($html);
    }

    /**
     * 表格里写 [[debug: x | 标签]] 时,label 分隔符 | 会被 GFM 当成单元格分隔 → shortcode 被撕成两半、
     * 多出的"单元格"还会把后面整列内容挤掉(GFM 丢弃超额单元格,静默丢数据)。
     *
     * 解法:在交给 commonmark 前,把 shortcode 内部的 | 预转义成 \|。GFM 表格解析见 \| 不切单元格、
     * 反转义回字面 | 再喂行内解析(DocShortcodeParser 的分隔符已容忍 \?\|);表格外 \| 也由同一正则命中。
     * 跳过代码(围栏 ``` / ~~~ 与行内 `…`)—— 那里的 [[…|…]] 是讲语法的示例文本,不能动它。幂等(已写 \| 不重复转义)。
     */
    private function escapeShortcodePipes(string $markdown): string
    {
        return (string) preg_replace_callback(
            '/(?P<code>```.*?```|~~~.*?~~~|`[^`\n]*`)|(?P<sc>\[\[[^\[\]\n]*\]\])/s',
            static function (array $m): string {
                if (($m['code'] ?? '') !== '') {
                    return $m['code'];                  // 代码片段原样保留
                }
                $sc = str_replace('\\|', '|', $m['sc']); // 先归一,防对已转义的再转义

                return str_replace('|', '\\|', $sc);     // 内部 | 统一转义成 \|
            },
            $markdown,
        );
    }

    /**
     * 把 commonmark 产出的 <pre><code class="language-mermaid">SRC</code></pre>
     * 换成隔离 iframe 渲染容器。SRC 已被 commonmark HTML 转义，原样放进 hidden <pre>，
     * 前端 textContent 读出即还原成 mermaid 源。
     */
    private function transformMermaid(string $html): string
    {
        $frameUrl = htmlspecialchars(route('docs.diagram'), ENT_QUOTES, 'UTF-8');

        return (string) preg_replace_callback(
            '#<pre><code class="language-mermaid">(.*?)</code>\s*</pre>#s',
            static function (array $m) use ($frameUrl): string {
                // 不加 sandbox:隔离靠的是"独立文档 = 独立 CSP 头"(SecurityHeaders 给 docs.diagram
                // 单独放宽 + frame-ancestors 'self'),不是 sandbox。帧是可信第一方(本站渲染器)+
                // mermaid securityLevel:'strict' 已禁掉图内脚本/HTML;allow-scripts+allow-same-origin
                // 既会触发"可逃逸沙箱"告警、又让帧 script-src 'self' 失配,得不偿失。
                return '<figure class="doc-mermaid" data-doc-mermaid>'
                    . '<pre class="doc-mermaid__src" hidden>' . $m[1] . '</pre>'
                    . '<iframe class="doc-mermaid__frame" src="' . $frameUrl . '" '
                    . 'loading="lazy" title="流程图" scrolling="no"></iframe>'
                    . '</figure>';
            },
            $html,
        );
    }
}
