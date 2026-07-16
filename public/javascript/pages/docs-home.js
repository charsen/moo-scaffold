/**
 * 文档目录主页(docs/home)—— 拖拽排序。
 *
 * 组内行拖(data-drag-handle="row")+ 组块整体拖(data-drag-handle="group");跨组不允许
 * (跨组 = 改 frontmatter group:,不在本页职责,拖行只在本组 ul 内找插入位)。
 * 松手后顺序真变了才 POST docs/reorder,提交该源全量 slug 顺序(组块顺序即组顺序),
 * 后端全局编号 10/20/30 写回各篇 frontmatter 的 order: 行。
 * 成功:就地重排行序号徽标 + 重取侧栏导航(Alpine 对注入节点自动初始化);
 * 失败:toast 报错后整页刷新回真实状态(常见于并发增删文档,后端严格校验拒绝)。
 * 请求在途禁止再拖,防竞态。
 */
(function () {
    var CFG = window.ScaffoldDocsHome || {};
    if (CFG.locked) return;

    var saving = false;

    function toast(msg, type) {
        if (window.scaffoldToast) window.scaffoldToast(msg, type);
        else if (type === 'error') window.alert(msg);
    }

    /** 该源当前展示顺序的全量 slug(跨组按 DOM 顺序拼接)。 */
    function snapshot(section) {
        return Array.prototype.map.call(section.querySelectorAll('.p-docs-home__row'), function (el) {
            return el.getAttribute('data-slug');
        });
    }

    /** 保存成功后就地重排序号徽标:DOM 顺序 × 10,与后端编号规则一致。 */
    function renumber(section) {
        var rows = section.querySelectorAll('.p-docs-home__row');
        for (var i = 0; i < rows.length; i++) {
            var badge = rows[i].querySelector('.p-docs-home__order');
            if (badge) badge.textContent = String((i + 1) * 10);
        }
    }

    function save(section, slugs) {
        saving = true;
        section.classList.add('is-saving');
        $.ajax({
            url: CFG.routes.reorder,
            type: 'POST',
            data: { src: section.getAttribute('data-reorder-src'), slugs: slugs },
            success: function (res) {
                renumber(section);
                var n = res && typeof res.changed === 'number' ? res.changed : null;
                toast('排序已保存' + (n !== null ? '（改写 ' + n + ' 篇）' : ''), 'success');
                // 侧栏导航就地刷新(重取本页抽出 .p-docs-nav 换入;失败也无妨,下次导航自然新)
                $('#aside_container').load(window.location.pathname + ' .p-docs-nav');
            },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || '排序保存失败';
                toast(msg, 'error');
                setTimeout(function () { window.location.reload(); }, 1500);   // 回真实状态
            },
            complete: function () {
                saving = false;
                section.classList.remove('is-saving');
            }
        });
    }

    function initSection(section) {
        if (section.getAttribute('data-reorder-enabled') !== '1') return;

        section.addEventListener('pointerdown', function (e) {
            if (saving || e.button !== 0) return;
            var handle = e.target.closest('[data-drag-handle]');
            if (!handle || !section.contains(handle)) return;

            var mode = handle.getAttribute('data-drag-handle');   // row | group
            var item = mode === 'row'
                ? handle.closest('.p-docs-home__row')
                : handle.closest('.p-docs-home__group');
            var container = mode === 'row'
                ? (item && item.parentElement)                              // 本组的 ul(天然限制跨组)
                : section.querySelector('.p-docs-home__groups');
            if (!item || !container) return;

            e.preventDefault();
            var before = snapshot(section).join('\n');
            var lastY = e.clientY;
            var raf = null;

            item.classList.add('is-dragging');
            document.body.classList.add('is-row-dragging');
            // 注意:不能 setPointerCapture(handle)——place() 把行(含把手)insertBefore 重插 DOM
            // 会令 capture 丢失,pointerup 从此到不了 handle,拖拽卡死。监听挂 window 才稳。

            // 指针 Y → 找第一个中线在其下方的兄弟,插它前面;都不满足挪到末尾
            function place(y) {
                var next = null;
                var kids = container.children;
                for (var i = 0; i < kids.length; i++) {
                    if (kids[i] === item) continue;
                    var r = kids[i].getBoundingClientRect();
                    if (y < r.top + r.height / 2) { next = kids[i]; break; }
                }
                if (next !== item.nextElementSibling && next !== item) {
                    container.insertBefore(item, next);   // next=null → append
                }
            }

            // 长列表拖到视口边缘自动滚动(纯 pointermove 停手就不滚,rAF 循环补上)
            function autoScroll() {
                var m = 80, v = 0;
                if (lastY < m) v = -Math.ceil((m - lastY) / 6);
                else if (lastY > window.innerHeight - m) v = Math.ceil((lastY - (window.innerHeight - m)) / 6);
                if (v) { window.scrollBy(0, v); place(lastY); }
                raf = window.requestAnimationFrame(autoScroll);
            }
            raf = window.requestAnimationFrame(autoScroll);

            function onMove(ev) {
                lastY = ev.clientY;
                place(lastY);
            }
            function teardown() {
                window.cancelAnimationFrame(raf);
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
                item.classList.remove('is-dragging');
                document.body.classList.remove('is-row-dragging');
            }
            function onUp() {
                teardown();
                var after = snapshot(section);
                if (after.join('\n') !== before) save(section, after);
            }

            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        });
    }

    var sections = document.querySelectorAll('[data-reorder-src]');
    for (var i = 0; i < sections.length; i++) initSection(sections[i]);
})();
