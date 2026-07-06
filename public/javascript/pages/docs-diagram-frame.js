/**
 * plan-52 Mermaid 隔离渲染帧的引导脚本（在 /scaffold/docs/_diagram 这一个隔离文档里运行）。
 * 该文档单独放宽了 CSP（script 'unsafe-eval' + style 'unsafe-inline'），mermaid 的 eval / 注入样式
 * 全关在此帧，主站 CSP 不动。图源不在本页 —— 由父页 postMessage 传入，渲染纯客户端。
 */
(function () {
    var ORIGIN = window.location.origin;
    var el = document.getElementById('d');
    var seq = 0;

    function postParent(msg) {
        msg.source = 'scaffold-docs-frame';
        try { window.parent.postMessage(msg, ORIGIN); } catch (e) {}
    }
    function reportHeight() {
        postParent({ type: 'rendered', height: Math.max(el.scrollHeight, el.offsetHeight) + 8 });
    }
    // mermaid 的 `click 节点 href "url"` 在 SVG 里生成 <a>,但不带 target。本帧是隔离 iframe,
    // 默认同帧打开会把目标页(/scaffold/db|api,带 frame-ancestors:none)塞进本帧被浏览器拒 → 看着"点不开"。
    // 两条一起兜底:① 设 target=_blank(中键/新标签页这条够);② 左键再用 JS window.open 显式开新窗
    //   —— 部分浏览器(Safari)不认 SVG <a> 的 target,只靠 ① 会失效。preventDefault 防双开。
    function openInNewTab(ev) {
        var a = ev.currentTarget;
        var url = a.getAttribute('xlink:href') || a.getAttribute('href') || '';
        if (!url) return;
        ev.preventDefault();
        window.open(url, '_blank', 'noopener');
    }
    // 给可点节点右上角加一个外链图标,明示"点开新窗" —— 不然用户看不出哪些节点能点。
    // 用 getBBox 定位(矩形/圆柱/菱形等各形状都成立),图标设 pointer-events:none,点击仍落到 <a>。
    var SVGNS = 'http://www.w3.org/2000/svg';
    var ICON_PATHS = ['M14 3h7v7', 'M21 3l-9 9', 'M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5'];
    function addClickIcon(a) {
        var nodeG = a.querySelector('g.node');
        if (!nodeG || nodeG.querySelector('.doc-click-ico')) return;
        var bb;
        try { bb = nodeG.getBBox(); } catch (e) { return; }
        var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent-solid').trim() || '#1a73e8';
        var g = document.createElementNS(SVGNS, 'g');
        g.setAttribute('class', 'doc-click-ico');
        g.setAttribute('transform', 'translate(' + (bb.x + bb.width - 16) + ',' + (bb.y + 2) + ') scale(0.6)');
        g.setAttribute('pointer-events', 'none');
        g.setAttribute('fill', 'none');
        g.setAttribute('stroke', accent);
        g.setAttribute('stroke-width', '2.4');
        g.setAttribute('stroke-linecap', 'round');
        g.setAttribute('stroke-linejoin', 'round');
        for (var k = 0; k < ICON_PATHS.length; k++) {
            var p = document.createElementNS(SVGNS, 'path');
            p.setAttribute('d', ICON_PATHS[k]);
            g.appendChild(p);
        }
        nodeG.appendChild(g);
    }
    function hardenLinks() {
        var links = el.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            links[i].setAttribute('target', '_blank');
            links[i].setAttribute('rel', 'noopener');
            links[i].addEventListener('click', openInNewTab);
            addClickIcon(links[i]);
        }
    }
    function renderCode(code, theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
        if (typeof window.mermaid === 'undefined') {
            el.innerHTML = '<pre class="doc-diagram-frame__err">Mermaid 未加载</pre>';
            reportHeight();
            return;
        }
        try {
            window.mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                theme: theme === 'dark' ? 'dark' : 'default',
                flowchart: { useMaxWidth: true }
            });
            seq += 1;
            window.mermaid.render('mmd' + seq, code).then(function (out) {
                el.innerHTML = out.svg;
                hardenLinks();
                reportHeight();
            }).catch(function (err) {
                el.innerHTML = '<pre class="doc-diagram-frame__err">流程图语法错误：\n' + String(err && err.message ? err.message : err) + '</pre>';
                reportHeight();
            });
        } catch (err) {
            el.innerHTML = '<pre class="doc-diagram-frame__err">流程图渲染失败：\n' + String(err && err.message ? err.message : err) + '</pre>';
            reportHeight();
        }
    }

    window.addEventListener('message', function (ev) {
        if (ev.origin !== ORIGIN) return;
        var d = ev.data || {};
        if (d.source !== 'scaffold-docs') return;
        if (d.type === 'render') renderCode(String(d.code || ''), d.theme);
    });

    // iframe 元素被父页 resize(浏览器窗口变化)→ 帧内 window 收到 resize → 防抖重新量高回报:
    // 图按新宽度 CSS 缩放后高度变了,iframe 高度若停在旧值会底部留空白 / 被裁,这里让它跟着窗口变。
    var resizeT = null;
    window.addEventListener('resize', function () {
        if (resizeT) clearTimeout(resizeT);
        resizeT = setTimeout(reportHeight, 150);
    });

    // 握手:监听器就绪后告诉父页"可以发图源了",避开 load 时序竞态
    postParent({ type: 'ready' });
})();
