/**
 * plan-52 文档中心 —— Mermaid 隔离 iframe 宿主。
 *
 * 每个 ```mermaid 块在服务端被渲染成:
 *   <figure data-doc-mermaid>
 *     <pre class="doc-mermaid__src" hidden>HTML 转义后的图源</pre>
 *     <iframe class="doc-mermaid__frame" src="/scaffold/docs/_diagram" sandbox="allow-scripts allow-same-origin">
 *   </figure>
 * 本脚本读出图源,经 postMessage 喂给隔离帧渲染(帧单独放宽 CSP),帧回报高度后回填 iframe 高度。
 * 阅读页 DOMContentLoaded 自动 wire;编辑器实时预览注入新 HTML 后调 window.scaffoldDocsRenderMermaid(container)。
 */
(function () {
    var ORIGIN = window.location.origin;
    var frames = []; // [{ iframe, code, ready }]

    function theme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'default';
    }

    function entryFor(win) {
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].iframe.contentWindow === win) return frames[i];
        }
        return null;
    }

    function postRender(entry) {
        if (!entry || entry.code == null) return;
        try {
            entry.iframe.contentWindow.postMessage(
                { source: 'scaffold-docs', type: 'render', code: entry.code, theme: theme() },
                ORIGIN
            );
        } catch (e) {}
    }

    window.addEventListener('message', function (ev) {
        if (ev.origin !== ORIGIN) return;
        var d = ev.data || {};
        if (d.source !== 'scaffold-docs-frame') return;
        var entry = entryFor(ev.source);
        if (!entry) return;
        if (d.type === 'ready') {
            entry.ready = true;
            postRender(entry);   // 帧握手就绪 → 发图源（覆盖"帧先于 wire 就绪"的竞态）
        } else if (d.type === 'rendered') {
            var h = parseInt(d.height, 10);
            if (h > 0) entry.iframe.style.height = h + 'px';
        }
    });

    function wire(container) {
        // 剔除已从 DOM 移除的旧帧（编辑器频繁重渲预览，防泄漏）
        frames = frames.filter(function (e) { return e.iframe.isConnected; });

        var figs = (container || document).querySelectorAll('[data-doc-mermaid]');
        for (var i = 0; i < figs.length; i++) {
            var fig = figs[i];
            if (fig.getAttribute('data-wired') === '1') continue;
            fig.setAttribute('data-wired', '1');

            var srcEl = fig.querySelector('.doc-mermaid__src');
            var iframe = fig.querySelector('.doc-mermaid__frame');
            if (!srcEl || !iframe) continue;

            var entry = { iframe: iframe, code: srcEl.textContent || '', ready: false };
            frames.push(entry);
            postRender(entry);   // 帧可能已就绪（缓存命中）→ 立即发；否则等帧的 ready 握手
        }
    }

    window.scaffoldDocsRenderMermaid = wire;

    // 主题切换:mermaid 渲染在隔离帧的独立文档里,不会跟着主站 data-theme 自己变色 →
    // 监听 data-theme 变化,变了就让所有在连帧按新主题重渲(亮暗一致)。
    if (typeof MutationObserver === 'function') {
        var lastTheme = theme();
        new MutationObserver(function () {
            var t = theme();
            if (t === lastTheme) return;
            lastTheme = t;
            frames = frames.filter(function (e) { return e.iframe.isConnected; });
            frames.forEach(postRender);
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    // ----- 右侧目录(TOC):从正文 h2/h3 现算 + scroll-spy(仅阅读页有 #doc_toc) -----
    function buildToc() {
        var article = document.getElementById('doc_article');
        var tocWrap = document.getElementById('doc_toc');
        var tocList = document.getElementById('doc_toc_list');
        if (!article || !tocWrap || !tocList) return;

        var heads = article.querySelectorAll('h2, h3');
        if (heads.length < 1) return;   // 一个标题都没有才不显示(单标题文档也给目录)

        var links = {};
        Array.prototype.forEach.call(heads, function (h, i) {
            if (!h.id) {
                var slug = (h.textContent || '').trim().replace(/\s+/g, '-').slice(0, 40);
                h.id = 'h-' + i + '-' + slug;
            }
            var a = document.createElement('a');
            a.href = '#' + h.id;
            a.className = 'p-docs-toc__link' + (h.tagName === 'H3' ? ' p-docs-toc__link--h3' : '');
            a.textContent = (h.textContent || '').trim();
            a.addEventListener('click', function (e) {
                e.preventDefault();
                h.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (history.replaceState) history.replaceState(null, '', '#' + h.id);
            });
            tocList.appendChild(a);
            links[h.id] = a;
        });

        tocWrap.removeAttribute('hidden');

        // scroll-spy:标题进入顶部 ~30% 带 → 高亮对应目录项
        if (typeof IntersectionObserver === 'function') {
            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (! en.isIntersecting) return;
                    Object.keys(links).forEach(function (k) { links[k].classList.remove('is-active'); });
                    if (links[en.target.id]) links[en.target.id].classList.add('is-active');
                });
            }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });
            Array.prototype.forEach.call(heads, function (h) { obs.observe(h); });
        }
    }

    // ----- 正文宽度开关(限宽 / 全宽,读者偏好存 localStorage,跨文档/刷新记住)-----
    function wireWidthToggle() {
        var article = document.getElementById('doc_article');
        var btn = document.getElementById('doc_width_toggle');
        if (!article || !btn) return;
        var KEY = 'scaffold_docs_width';
        function apply(narrow) {
            article.classList.toggle('is-narrow', narrow);
            // 按钮显示「点一下会切到的模式」(动作),不是当前状态 —— 否则读着是反的
            btn.textContent = narrow ? '全宽' : '限宽';
            btn.setAttribute('title', narrow ? '当前限宽(舒适行宽),点击切全宽' : '当前全宽,点击切限宽(舒适行宽)');
        }
        var narrow = false;   // 默认全宽,只有显式存了 'narrow' 才限宽
        try { narrow = localStorage.getItem(KEY) === 'narrow'; } catch (e) {}
        apply(narrow);
        btn.addEventListener('click', function () {
            narrow = !narrow;
            try { localStorage.setItem(KEY, narrow ? 'narrow' : 'wide'); } catch (e) {}
            apply(narrow);
        });
    }

    // ----- 复制到剪贴板（localhost / https 走 Clipboard API,其它降级 execCommand）-----
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
        }
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return Promise.resolve(ok);
        } catch (e) {
            return Promise.resolve(false);
        }
    }
    function toast(msg, type) {
        if (typeof window.scaffoldToast === 'function') window.scaffoldToast(msg, type || 'info');
    }

    // ----- 代码块工具条:给每个 <pre><code> 包一层,顶部显示语言 + 复制按钮 -----
    // mermaid 的隐藏源是 <pre class="doc-mermaid__src">(无 <code> 子节点)→ pre>code 天然跳过,不会误包。
    function wireCodeBlocks(root) {
        var codes = (root || document).querySelectorAll('pre > code');
        for (var i = 0; i < codes.length; i++) {
            var code = codes[i];
            var pre = code.parentNode;
            if (!pre || pre.getAttribute('data-cb') === '1') continue;
            if (!(code.textContent || '').trim()) continue;   // 空 ``` ``` 块:不包工具条(否则空黑壳 + 没内容可复制的按钮)
            pre.setAttribute('data-cb', '1');

            var lang = '';
            var m = (code.className || '').match(/language-([\w+#.-]+)/);
            if (m) lang = m[1];

            var wrap = document.createElement('div');
            wrap.className = 'doc-codeblock';
            var bar = document.createElement('div');
            bar.className = 'doc-codeblock__bar';
            var label = document.createElement('span');
            label.className = 'doc-codeblock__lang';
            label.textContent = lang || 'code';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'doc-codeblock__copy';
            btn.textContent = '复制';
            (function (codeEl, button) {
                button.addEventListener('click', function () {
                    copyText(codeEl.textContent || '').then(function (ok) {
                        button.textContent = ok ? '已复制' : '复制失败';
                        setTimeout(function () { button.textContent = '复制'; }, 1500);
                    });
                });
            })(code, btn);
            bar.appendChild(label);
            bar.appendChild(btn);

            pre.parentNode.insertBefore(wrap, pre);   // 把 wrap 插到 pre 原位,再把 pre 收进 wrap(保持文档流顺序)
            wrap.appendChild(bar);
            wrap.appendChild(pre);
        }
    }
    window.scaffoldDocsRenderCode = wireCodeBlocks;

    // ----- 标题悬停锚点:每个标题左侧注入 # 链接,点击复制本节深链 URL(仅阅读页有 #doc_article) -----
    // 注:必须在 buildToc() 之后调用 —— buildToc 读 h.textContent 建目录,先插 # 会把「#」混进目录文字。
    function wireHeadingAnchors() {
        var article = document.getElementById('doc_article');
        if (!article) return;
        var heads = article.querySelectorAll('h1, h2, h3, h4');
        Array.prototype.forEach.call(heads, function (h, i) {
            if (h.querySelector('.doc-anchor')) return;
            if (!h.id) {
                var slug = (h.textContent || '').trim().replace(/\s+/g, '-').slice(0, 40);
                h.id = 'h-' + i + '-' + slug;
            }
            var a = document.createElement('a');
            a.className = 'doc-anchor';
            a.href = '#' + h.id;
            a.title = '复制本节链接';
            a.setAttribute('aria-label', '复制本节链接');
            a.textContent = '#';
            a.addEventListener('click', function (e) {
                e.preventDefault();
                h.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (history.replaceState) history.replaceState(null, '', '#' + h.id);
                var url = location.origin + location.pathname + location.search + '#' + h.id;
                copyText(url).then(function (ok) { if (ok) toast('已复制本节链接', 'success'); });
            });
            h.insertBefore(a, h.firstChild);
        });
    }

    function initReader() {
        var article = document.getElementById('doc_article');
        wire(document);                       // mermaid 帧
        wireCodeBlocks(article || document);  // 代码块工具条:限定正文内,别误包 shell/组件里的 <pre><code>
        buildToc();                           // 目录(读 textContent,须在注锚点前)
        wireHeadingAnchors();                 // 标题悬停锚点(buildToc 之后)
        wireWidthToggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReader);
    } else {
        initReader();
    }
})();
