/**
 * plan-52 文档编辑器。
 *   - textarea 输入 → 防抖 POST /docs/preview → 注入预览 → wire Mermaid(docs-center.js)
 *   - 保存 / 删除 → POST,成功跳转阅读页
 *   - 接口 / 数据库引用 picker(数据来自 /docs/picker)→ 光标处插入 shortcode
 *   - 插入流程图骨架
 * production / 只读时整页 data-locked(SCSS 灰按钮),且这里跳过交互绑定,避免无谓的 403 预览请求。
 */
(function () {
    var $ = window.jQuery;
    var CFG = window.ScaffoldDocsEditor;
    if (!$ || !CFG) return;

    var $content = $('#doc_content');
    var $slug    = $('#doc_slug');
    var $preview = $('#doc_preview');

    // ---------- 字号 A− / A+（与阅读页共用 localStorage 键,跨页/刷新记住;同时缩放源码框 + 预览）----------
    // 放在 locked-return 之前:即便生产只读(按钮被 SCSS 禁点),存过的字号也先套到预览上。
    (function wireEditorFontSize() {
        var textarea = $content[0], preview = $preview[0];
        var dec = document.getElementById('doc_edit_font_dec');
        var inc = document.getElementById('doc_edit_font_inc');
        if (!textarea || !preview || !dec || !inc) return;
        var KEY = 'scaffold_docs_font_scale';   // 与阅读页(docs-center.js wireFontSize)同一键
        var MIN = 0.85, MAX = 1.6, STEP = 0.1;
        function clamp(v) { return Math.min(MAX, Math.max(MIN, Math.round(v * 10) / 10)); }
        var scale = 1;
        try { var saved = parseFloat(localStorage.getItem(KEY)); if (!isNaN(saved)) scale = clamp(saved); } catch (e) {}
        function apply() {
            textarea.style.setProperty('--doc-font-scale', String(scale));   // 源码框:calc(--font-base × 倍率)
            preview.style.setProperty('--doc-font-scale', String(scale));    // 预览 .doc-article:同倍率
            dec.disabled = scale <= MIN;
            inc.disabled = scale >= MAX;
            var pct = Math.round(scale * 100);
            dec.setAttribute('title', '缩小字号（当前 ' + pct + '%）');
            inc.setAttribute('title', '放大字号（当前 ' + pct + '%）');
        }
        function step(delta) {
            scale = clamp(scale + delta);
            try { localStorage.setItem(KEY, String(scale)); } catch (e) {}
            apply();
        }
        apply();
        dec.addEventListener('click', function () { step(-STEP); });
        inc.addEventListener('click', function () { step(STEP); });
    })();

    // plan-53 出身:文档落点源('' = host,否则扩展包 key)。
    // 只有真·新建未存(CFG.isNew)时读 #doc_src 下拉;身份一旦冻结(既有文档 / 首存成功后
    // CFG.src 赋值 + CFG.isNew=false)就认 CFG.src,不再读下拉。
    // 防竞态:首存在途时用户改下拉 → disabled select 的 .val() 仍是新值(jQuery 照读),若继续读会把
    // 排队的 autosave 写到别的源(同 slug 落两个包仓 = 重复/孤儿文件)。冻结后认 CFG.src 即堵死。
    function docSrc() {
        var $s = $('#doc_src');
        return (CFG.isNew && $s.length) ? ($s.val() || '') : (CFG.src || '');
    }

    function toast(msg, type) {
        if (window.scaffoldToast) window.scaffoldToast(msg, type || 'info');
        else window.alert(msg);
    }

    // ---------- 实时预览（防抖） ----------
    var previewTimer = null, previewSeq = 0;
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 350);
    }
    function renderPreview() {
        var seq = ++previewSeq;   // 慢响应乱序到达时,丢弃过期那笔,防旧预览盖掉新内容(对齐 save 流程的并发守护)
        $.ajax({
            url: CFG.routes.preview, type: 'POST', data: { content: $content.val() },
            success: function (res) {
                if (seq !== previewSeq) return;
                $preview.html((res && res.html) || '');
                if (typeof window.scaffoldDocsRenderMermaid === 'function') {
                    window.scaffoldDocsRenderMermaid($preview[0]);
                }
                if (typeof window.scaffoldDocsRenderCode === 'function') {
                    window.scaffoldDocsRenderCode($preview[0]);   // 代码块工具条(语言 + 复制)
                }
            }
        });
    }

    // locked（生产/只读）：只渲染一次预览（GET 安全？预览是 POST，会被中间件拒）——直接不发，
    // 编辑器在生产本就停用，预览空着即可，不制造 403 噪声。
    if (CFG.locked) return;

    renderPreview();

    // ---------- 源码 ↔ 预览 同步滚动(按比例) ----------
    // 长文档编辑时两栏各滚各的,预览找不到光标对应位置 → 按滚动比例联动。markdown 行数 ≠ 渲染高度,
    // 做不到行级精确,比例联动已够用且轻量。syncing 闸防回弹:设对端 scrollTop 会触发其 scroll 事件,
    // 同一帧内忽略,下一帧释放。
    var previewScroller = $('#doc_preview').parent()[0];   // .p-docs-editor__pane--preview(overflow-y:auto 的滚动体)
    if (previewScroller) {
        var syncing = false;
        function syncScroll(src, dst) {
            var sMax = src.scrollHeight - src.clientHeight;
            if (sMax <= 0) return;
            var dMax = dst.scrollHeight - dst.clientHeight;
            dst.scrollTop = (src.scrollTop / sMax) * dMax;
        }
        function bindScroll(src, dst) {
            $(src).on('scroll', function () {
                if (syncing) return;
                syncing = true;
                syncScroll(src, dst);
                window.requestAnimationFrame(function () { syncing = false; });
            });
        }
        bindScroll($content[0], previewScroller);
        bindScroll(previewScroller, $content[0]);
    }

    // ---------- 保存状态指示 ----------
    var $status = $('#doc_save_status');
    function setStatus(state) {
        if (!$status.length) return;
        var map = { dirty: '未保存…', saving: '保存中…', saved: '已保存', error: '保存失败', needpath: '填路径后自动保存' };
        $status.text(map[state] || '').attr('class', 'p-docs-editor__status is-' + (state || ''));
    }

    // ---------- 边输入边保存(防抖)+ 手动保存,均不跳转,留在编辑器 ----------
    var saveTimer = null, saving = false, pendingSave = false, dirty = false;

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () { doSave(true); }, 1200);
    }
    function doSave(silent) {
        var slug = $.trim($slug.val());
        if (!slug) {
            if (silent) setStatus('needpath');
            else { toast('请先填写文档路径（左上角）', 'warning'); $slug.focus(); }
            return;
        }
        if (saving) { pendingSave = true; return; }   // 一笔在飞,别并发写同一文件;完成后补一笔
        saving = true;
        setStatus('saving');
        var src = docSrc();
        $.ajax({
            url: CFG.routes.save, type: 'POST', data: { slug: slug, content: $content.val(), src: src },
            success: function () {
                dirty = false;
                setStatus('saved');
                // 新建文档首存成功 → 锁定身份(slug + 落点源只读)+ 地址换成该文档编辑页(刷新能回到这)
                if (CFG.isNew) {
                    CFG.isNew = false;
                    CFG.src = src;
                    $slug.attr('readonly', 'readonly');
                    $('#doc_src').prop('disabled', true);   // 首存后出身定死,防止后续自动保存漂到别的源
                    if (window.history && history.replaceState && CFG.routes.edit) {
                        var tq = src ? '&src=' + encodeURIComponent(src) : '';
                        history.replaceState(null, '', CFG.routes.edit + '?doc=' + encodeURIComponent(slug) + tq);
                    }
                }
            },
            error: function (xhr) {
                setStatus('error');
                if (!silent) toast((xhr.responseJSON && xhr.responseJSON.error) || '保存失败', 'danger');
            },
            complete: function () {
                saving = false;
                if (pendingSave) { pendingSave = false; doSave(true); }
            }
        });
    }

    // ---------- 光标处插入 ----------
    function insertAtCursor(text) {
        var el = $content[0];
        var s = el.selectionStart || 0, e = el.selectionEnd || 0, v = el.value;
        el.value = v.slice(0, s) + text + v.slice(e);
        el.selectionStart = el.selectionEnd = s + text.length;
        el.focus();
        dirty = true;
        schedulePreview();
        scheduleSave();
    }

    // 输入 → 预览 + 标脏 + 排期自动保存
    $content.on('input', function () {
        dirty = true;
        setStatus('dirty');
        schedulePreview();
        scheduleSave();
    });

    // 手动保存(按钮 / Cmd+S):立即存、不跳转,留在编辑器。
    // Cmd+S 交给 main.js 的全局快捷键统一处理 —— 它 preventDefault 浏览器「保存网页」后,会点
    // [data-shortcut="save"] 按钮(就是下面这个)。这里不再自绑 document keydown:否则跟 main.js 双触发,
    // 更要命的是若 main.js 找不到 save 按钮,它的 fallback 会提交「整页第一个 POST 表单」= header 的登出表单。
    $('#doc_save').on('click', function () { clearTimeout(saveTimer); doSave(false); });

    // 返回阅读:有未存改动先冲一笔再走,避免丢最后一秒输入
    $('#doc_back').on('click', function (e) {
        var slug = $.trim($slug.val());
        if (!dirty || !slug) return;     // 干净 / 没路径 → 正常跳转
        e.preventDefault();
        var href = $(this).attr('href');
        clearTimeout(saveTimer);
        $.ajax({
            url: CFG.routes.save, type: 'POST', data: { slug: slug, content: $content.val(), src: docSrc() },
            complete: function () { window.location.href = href; }
        });
    });

    // Tab 缩进:markdown / mermaid / yaml 都吃 2 空格缩进,Tab 默认会跳出 textarea → 写文档很烦。
    // 无选区 Tab 插 2 空格;多行选区 Tab 整体缩进、Shift+Tab 反缩进(各行去掉最多 2 个前导空格 / 1 个 tab)。
    var INDENT = '  ';
    $content.on('keydown', function (e) {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        var el = $content[0], s = el.selectionStart, en = el.selectionEnd, v = el.value;

        if (s === en && !e.shiftKey) {                       // 无选区 + Tab → 光标处插 2 空格
            el.value = v.slice(0, s) + INDENT + v.slice(en);
            el.selectionStart = el.selectionEnd = s + INDENT.length;
        } else {                                             // 选区 / Shift+Tab → 按行(反)缩进
            var lineStart = v.lastIndexOf('\n', s - 1) + 1;  // 选区首行行首
            var lines = v.slice(lineStart, en).split('\n');
            if (e.shiftKey) {
                var cutFirst = 0, cutTotal = 0;
                lines = lines.map(function (ln, i) {
                    var m = ln.match(/^( {1,2}|\t)/);
                    var cut = m ? m[0].length : 0;
                    if (i === 0) cutFirst = cut;
                    cutTotal += cut;
                    return ln.slice(cut);
                });
                el.value = v.slice(0, lineStart) + lines.join('\n') + v.slice(en);
                el.selectionStart = Math.max(lineStart, s - cutFirst);
                el.selectionEnd = en - cutTotal;
            } else {
                lines = lines.map(function (ln) { return INDENT + ln; });
                el.value = v.slice(0, lineStart) + lines.join('\n') + v.slice(en);
                el.selectionStart = s + INDENT.length;
                el.selectionEnd = en + INDENT.length * lines.length;
            }
        }
        dirty = true; schedulePreview(); scheduleSave();
    });

    // ---------- 删除 ----------
    $('#doc_delete').on('click', function () {
        window.scaffoldConfirm({ message: '确定删除这篇文档？历史可经 git restore 找回。', danger: true })
            .then(function (ok) {
                if (!ok) return;
                $.ajax({
                    url: CFG.routes.remove, type: 'POST', data: { slug: CFG.slug, src: CFG.src || '' },
                    success: function (res) { window.location.href = res.redirect; },
                    error: function (xhr) { toast((xhr.responseJSON && xhr.responseJSON.error) || '删除失败', 'danger'); }
                });
            });
    });

    // ---------- 插入流程图骨架 ----------
    $('#doc_ins_mermaid').on('click', function () {
        insertAtCursor('\n```mermaid\nflowchart TD\n  A[开始] --> B{判断}\n  B -- 是 --> C[处理]\n  B -- 否 --> D[结束]\n```\n');
    });

    // ---------- 引用 picker ----------
    var catalog = null, pickerMode = 'api', apiType = 'debug', insertFmt = 'chip', activeIndex = -1;
    var $picker = $('#doc_picker'), $list = $('#doc_picker_list'), $search = $('#doc_picker_search'), $title = $('#doc_picker_title');

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function ensureCatalog(cb) {
        if (catalog) { cb(); return; }
        $.ajax({
            url: CFG.routes.picker, type: 'GET',
            success: function (res) { catalog = res || { endpoints: [], tables: [] }; cb(); },
            error: function () { toast('加载引用列表失败', 'danger'); }
        });
    }
    function openPicker(mode) {
        pickerMode = mode;
        $title.text(mode === 'api' ? '插入接口引用' : '插入数据库引用');
        // 接口类型分段(debug/api)仅接口引用时显示;数据库引用无此概念
        if (mode === 'api') { $('#doc_picker_apitype').removeAttr('hidden'); }
        else { $('#doc_picker_apitype').attr('hidden', 'hidden'); }
        $picker.removeAttr('hidden');
        $search.val('');
        ensureCatalog(function () { renderList(''); $search.trigger('focus'); });
    }
    function closePicker() { $picker.attr('hidden', 'hidden'); }

    function matches(it, q) {
        if (!q) return true;
        return (it.label + ' ' + it.target).toLowerCase().indexOf(q) > -1;
    }
    function apiRow(it) {
        return '<button type="button" class="p-docs-picker__item" data-target="' + esc(it.target) + '" data-label="' + esc(it.label) + '"'
            + ' data-url-debug="' + esc(it.url_debug || '') + '" data-url-doc="' + esc(it.url_doc || '') + '">'
            + '<span class="p-docs-picker__method">' + esc(it.method || '') + '</span>'
            + '<span class="p-docs-picker__label">' + esc(it.label) + '</span>'
            + '<code class="p-docs-picker__target">' + esc(it.target) + '</code>'
            + '</button>';
    }
    function dbRow(it) {
        return '<button type="button" class="p-docs-picker__item" data-target="' + esc(it.target) + '" data-label="' + esc(it.label) + '"'
            + ' data-url="' + esc(it.url || '') + '">'
            + '<span class="p-docs-picker__label">' + esc(it.label) + '</span>'
            + '<code class="p-docs-picker__target">' + esc(it.target) + '</code>'
            + '</button>';
    }
    // 渲染封顶:大项目接口上千条,全量建 DOM ~120ms/次卡顿。只建前 RENDER_CAP 条,余量提示继续输入缩小。
    var RENDER_CAP = 150;
    function renderList(q) {
        q = (q || '').toLowerCase();
        var src = pickerMode === 'api' ? (catalog.endpoints || []) : (catalog.tables || []);
        var row = pickerMode === 'api' ? apiRow : dbRow;
        var rows = [], matched = 0;
        for (var i = 0; i < src.length; i++) {
            if (!matches(src[i], q)) continue;
            matched++;
            if (matched <= RENDER_CAP) rows.push(row(src[i]));
        }
        if (!rows.length) {
            $list.html('<p class="p-docs-picker__none">无匹配</p>');
        } else if (matched > RENDER_CAP) {
            $list.html(rows.join('') + '<p class="p-docs-picker__none">还有 ' + (matched - RENDER_CAP) + ' 条，继续输入缩小范围…</p>');
        } else {
            $list.html(rows.join(''));
        }
        setActive(0);   // 每次过滤后高亮第一条,供键盘 Enter 直插
    }

    // 键盘导航:↑/↓ 在结果里移动高亮,Enter 插入当前高亮(写文档插引用免摸鼠标)
    function pickerItems() { return $list.find('.p-docs-picker__item'); }
    function setActive(idx) {
        var $items = pickerItems();
        if (!$items.length) { activeIndex = -1; return; }
        activeIndex = Math.max(0, Math.min(idx, $items.length - 1));
        $items.removeClass('is-active');
        var el = $items.eq(activeIndex).addClass('is-active')[0];
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    }

    // 流程图 click 行:插入 `click 节点ID href "URL" "标签"`,并把「节点ID」选中,方便直接敲真节点名。
    function insertClickLine(url, label) {
        var el = $content[0];
        var s = el.selectionStart || 0, e = el.selectionEnd || 0, v = el.value;
        var prefix = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';   // 确保 click 独占一行
        var head = prefix + 'click ', ph = '节点ID';
        var line = head + ph + ' href "' + url + '" "' + label + '"';
        el.value = v.slice(0, s) + line + v.slice(e);
        var phStart = s + head.length;
        el.selectionStart = phStart;
        el.selectionEnd = phStart + ph.length;
        el.focus();
        dirty = true; schedulePreview(); scheduleSave();
    }

    $('#doc_ins_api').on('click', function () { openPicker('api'); });
    $('#doc_ins_db').on('click', function () { openPicker('db'); });
    $('#doc_picker_close, #doc_picker_backdrop').on('click', closePicker);
    var searchTimer = null;
    $search.on('input', function () {
        var v = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { renderList(v); }, 150);   // 防抖:对齐侧栏搜索,避免每键全量重渲
    });
    // ↑/↓ 移动高亮、Enter 插入高亮项(在搜索框里即可操作,不用点)
    $search.on('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIndex + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1); }
        else if (e.key === 'Enter') {
            var $items = pickerItems();
            if (activeIndex > -1 && $items.length) { e.preventDefault(); $items.eq(activeIndex).trigger('click'); }
        }
    });
    // 插入格式切换(正文 chip / 流程图 click)—— data-fmt 区分
    $('#doc_picker_fmt').on('click', '.p-docs-picker__segbtn', function () {
        insertFmt = $(this).attr('data-fmt');
        $('#doc_picker_fmt .p-docs-picker__segbtn').removeClass('is-active');
        $(this).addClass('is-active');
    });
    // 接口类型切换(debug/api)—— data-type 区分,改完重渲列表(条目本身不变,但保持选中态一致)
    $('#doc_picker_apitype').on('click', '.p-docs-picker__segbtn', function () {
        apiType = $(this).attr('data-type');
        $('#doc_picker_apitype .p-docs-picker__segbtn').removeClass('is-active');
        $(this).addClass('is-active');
    });
    $list.on('click', '.p-docs-picker__item', function () {
        var $it = $(this), label = $it.attr('data-label');
        if (insertFmt === 'click') {
            var url = pickerMode === 'api'
                ? (apiType === 'debug' ? $it.attr('data-url-debug') : $it.attr('data-url-doc'))
                : $it.attr('data-url');
            insertClickLine(url, label);
        } else {
            var type = pickerMode === 'api' ? apiType : 'db';
            insertAtCursor('[[' + type + ': ' + $it.attr('data-target') + ' | ' + label + ']]');
        }
        closePicker();
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closePicker(); });
})();
