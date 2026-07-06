<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\Markdown\DocMarkdownRenderer;

/**
 * plan-52 文档渲染单测:深链 shortcode 解析成已有只读路由 + 语法错可见报错 + Mermaid 转隔离 iframe
 * + 原始 HTML 转义(html_input=escape)。route() 走 Testbench 已注册的 scaffold 路由。
 */
beforeEach(function () {
    $this->r = app(DocMarkdownRenderer::class);
});

it('[[debug]] 解析成 api.request 深链 + target=_blank', function () {
    // href 里 & 在 HTML 中转义成 &amp;,断言只查 path + 各参数,不耦合连接符
    $html = $this->r->render('点 [[debug: admin/Market/Order@rate | 调试]]');
    expect($html)->toContain('/scaffold/api/request?app=admin');
    expect($html)->toContain('f=Market');
    expect($html)->toContain('c=Order');
    expect($html)->toContain('a=rate');
    expect($html)->toContain('doc-shortcode--debug');
    expect($html)->toContain('target="_blank"');
    expect($html)->toContain('调试');
});

it('[[api: …@action]] → api.list(完整页非 AJAX 片段);[[db: Mod.table]] → db.docs(schema+table)', function () {
    $html = $this->r->render('[[api: admin/Order@list]] [[db: Market.orders]]');
    expect($html)->toContain('/scaffold/api?app=admin');   // api.list 完整页,不是 api/show 片段
    expect($html)->not->toContain('/scaffold/api/show');
    expect($html)->toContain('f=Index');     // 无 folder 段 → 默认 Index
    expect($html)->toContain('c=Order');
    expect($html)->toContain('a=list');
    expect($html)->toContain('/scaffold/db/docs?schema=Market');
    expect($html)->toContain('table=orders');
});

it('[[api: …Controller]] 控制器级(无 @action) → api.list 定位到控制器,不报错', function () {
    $html = $this->r->render('[[api: admin/Market/ServiceHall]]');
    expect($html)->toContain('doc-shortcode--api');
    expect($html)->not->toContain('doc-shortcode--error');
    expect($html)->toContain('/scaffold/api?app=admin');
    expect($html)->toContain('f=Market');
    expect($html)->toContain('c=ServiceHall');
    expect($html)->not->toContain('a=');     // 无 action 段
});

it('[[db: Module]] 无表 → 只带 schema', function () {
    $html = $this->r->render('[[db: Market]]');
    expect($html)->toContain('/scaffold/db/docs?schema=Market');
    expect($html)->not->toContain('table=');
});

it('未知类型 / debug 缺 @action → 可见错误 chip(不静默)', function () {
    expect($this->r->render('[[bad: x]]'))->toContain('doc-shortcode--error');
    expect($this->r->render('[[debug: nostuff]]'))->toContain('doc-shortcode--error');
    // debug 是"调一个端点",控制器级(无 @action)对 debug 仍是错误
    expect($this->r->render('[[debug: admin/Market/Order]]'))->toContain('doc-shortcode--error');
});

it('表格单元格里带 | 标签的 shortcode 不被 GFM 撕裂,且整列内容不丢', function () {
    $md = "| 路径 | 入口 | 行为 |\n|---|---|---|\n"
        . '| 下单 | [[debug: admin/Market/Order@store | store 调试]] | 校验归属 |';
    $html = $this->r->render($md);

    // shortcode 渲染成 chip(不是裸文本 [[debug: …)
    expect($html)->toContain('doc-shortcode--debug');
    expect($html)->toContain('a=store');
    expect($html)->not->toContain('[[debug:');
    expect($html)->not->toContain('调试]]');
    // 行为列内容没被挤掉(历史:嵌入 | 多出单元格,GFM 静默丢弃尾列)
    expect($html)->toContain('校验归属');
    // 表头 3 列、数据行 3 个 td
    expect(substr_count($html, '<td>'))->toBe(3);
});

it('代码片段里的 [[…|…]] 不被预转义污染(围栏 / 行内 code 都保持字面)', function () {
    $inline = $this->r->render('语法:`[[db: Market.orders]]` 或 `[[api: a|b]]`');
    expect($inline)->not->toContain('\\|');           // 没注入反斜杠
    expect($inline)->toContain('<code>');
    expect($inline)->not->toContain('doc-shortcode');  // code 里不渲染成 chip

    $fence = $this->r->render("```\n[[debug: x | y]]\n```");
    expect($fence)->not->toContain('\\|');
    expect($fence)->not->toContain('doc-shortcode');
});

it('```mermaid 围栏块转成隔离 iframe + 源放进 hidden pre', function () {
    $html = $this->r->render("```mermaid\nflowchart TD\n  A-->B\n```");
    expect($html)->toContain('class="doc-mermaid"');
    expect($html)->toContain('/scaffold/docs/_diagram');
    expect($html)->toContain('doc-mermaid__src');
    // 源被 HTML 转义后内嵌(--> → --&gt;)
    expect($html)->toContain('A--&gt;B');
    expect($html)->not->toContain('<pre><code class="language-mermaid">');
});

it('原始 HTML 被转义(html_input=escape):正文里的 script 不落地成标签', function () {
    $html = $this->r->render('正文 <script>alert(1)</script> 结束');
    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
});

it('GFM 表格可渲染', function () {
    $html = $this->r->render("| a | b |\n|---|---|\n| 1 | 2 |");
    expect($html)->toContain('<table>');
    expect($html)->toContain('<td>1</td>');
});

// ---- 边界 probe(loop 自测加固) ----

it('shortcode 括号内多余空格也能解析', function () {
    $html = $this->r->render('[[ debug : admin/Market/Order@rate_put | 标签 ]]');
    expect($html)->toContain('doc-shortcode--debug');
    expect($html)->toContain('c=Order');
    expect($html)->toContain('a=rate_put');
    expect($html)->toContain('标签');
    expect($html)->not->toContain('doc-shortcode--error');
});

it('一行多个 shortcode 各自成 chip', function () {
    $html = $this->r->render('看 [[db: Market.orders]] 和 [[db: User]] 两处');
    expect(substr_count($html, 'doc-shortcode--db'))->toBe(2);
    expect($html)->toContain('table=orders');
});

it('shortcode 在列表 / 引用块里也渲染', function () {
    $li = $this->r->render("- 见 [[db: Market]]\n- 末项");
    expect($li)->toContain('<li>');
    expect($li)->toContain('doc-shortcode--db');
    $bq = $this->r->render('> 提示:[[api: admin/Market/Order]]');
    expect($bq)->toContain('<blockquote>');
    expect($bq)->toContain('doc-shortcode--api');
});

it('表格里 shortcode 的 | 与单元格内转义 \\| 共存,各列不串', function () {
    $md   = "| 表达式 | 链接 |\n|---|---|\n| a \\| b | [[debug: admin/Market/Order@rate_put | 调]] |";
    $html = $this->r->render($md);
    expect($html)->toContain('doc-shortcode--debug');     // shortcode 成 chip
    expect($html)->toContain('a | b');                    // 转义管道还原成字面
    expect($html)->not->toContain('[[debug:');            // 没裸文字
    expect(substr_count($html, '<td>'))->toBe(2);         // 两列没被 | 撑乱
});

it('shortcode 目标含 HTML/引号 → 转义成文本,不破出活标签(XSS 安全)', function () {
    $html = $this->r->render('[[db: a"><img src=x onerror=alert(1)>]]');
    expect($html)->not->toContain('<img');        // 没有活的 <img> 标签
    expect($html)->toContain('&lt;img');          // payload 被转义成纯文本
});

it('非 shortcode 的 [[...]](无冒号)保持字面,不误判成错误 chip', function () {
    $html = $this->r->render('数组写法 [[a, b], [c]] 不是 shortcode');
    expect($html)->not->toContain('doc-shortcode');
});
