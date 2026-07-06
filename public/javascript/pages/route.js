/**
 * 接口路由页 (/scaffold/routes) 专属脚本
 *
 * 依赖：jQuery（main.js 之后加载）；side-tree 组件（Alpine）协同高亮
 * 2026-06-20:从 route/index.blade.php 内联 <script>(232 行)整体外提，清 static-guards
 *            「>30 行内联脚本」告警。逻辑零改动，仅搬运。
 */
$(function () {
    var $search = $('#route_search');
    var $modules = $('#route_modules');
    var $sidebar = $('#route_sidebar');
    var $main = $('.route-main');

    // 搜索按钮（route 搜索是即时 input filter，按钮点击重新触发 input 事件 + focus）
    $('#route_search_btn').on('click', function () {
        $search.trigger('focus').trigger('input');
    });

    // 综合 filter:搜索 keyword + 白名单 chip,任一变化就重跑一遍
    var $whitelistFilter = $('#route_filter_whitelist');

    // plan-32:sidebar 迁 side-tree;hide 走 .is-hidden(side-tree 约定)
    function applyFilter() {
        var keyword = $.trim($search.val()).toLowerCase();
        var whitelistOnly = $whitelistFilter.is(':checked');

        $modules.find('.route-module').each(function () {
            var $module = $(this);
            var moduleKey = $module.data('module');
            var visibleCount = 0;

            $module.find('.route-row').each(function () {
                var $row = $(this);
                var text = $row.data('search') || '';
                var isWhitelist = $row.data('whitelist') === 1 || $row.data('whitelist') === '1';
                var pass = true;
                if (keyword && text.indexOf(keyword) === -1) pass = false;
                if (pass && whitelistOnly && !isWhitelist) pass = false;
                $row.toggle(pass);
                if (pass) visibleCount++;
            });

            // 同 controller 分组行(.route-group-row)联动:本组无可见 row 就藏起来
            $module.find('.route-group-row').each(function () {
                var $g = $(this);
                var anyVisible = false;
                $g.nextUntil('.route-group-row').each(function () {
                    if ($(this).hasClass('route-row') && $(this).is(':visible')) anyVisible = true;
                });
                $g.toggle(anyVisible);
            });

            $module.toggle(visibleCount > 0);
            // 一级:side-tree group(用 .is-hidden 跟组件搜索 filter 约定一致)
            $sidebar.find('.side-tree__group[data-group-key="' + moduleKey + '"]').toggleClass('is-hidden', visibleCount === 0);
            // 二级:每个 controller item,按可见 route 行重新判定
            $sidebar.find('.side-tree__item[data-module="' + moduleKey + '"]').each(function () {
                var ctrl = $(this).data('controller');
                var ctrlAnchor = '#ctrl-' + moduleKey + '-' + ctrl;
                var $anchorRow = $modules.find(ctrlAnchor);
                var anyVisible = false;
                if ($anchorRow.length) {
                    $anchorRow.nextUntil('.route-group-row').each(function () {
                        if ($(this).hasClass('route-row') && $(this).is(':visible')) anyVisible = true;
                    });
                }
                $(this).toggleClass('is-hidden', !anyVisible);
            });
        });
    }

    $search.on('input', applyFilter);
    $whitelistFilter.on('change', applyFilter);

    // 二级 chevron 折叠 / 展开由 side-tree 组件自接管(Alpine sideTree.toggleGroup + localStorage 持久化)

    // Module collapse toggle (鼠标 + 键盘 Enter/Space)
    function toggleModule($header) {
        var $module = $header.closest('.route-module');
        $module.toggleClass('collapsed');
        $header.attr('aria-expanded', !$module.hasClass('collapsed'));
    }
    $('.route-module-header').on('click', function () {
        toggleModule($(this));
    });
    $('.route-module-header').on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32) {
            e.preventDefault();
            toggleModule($(this));
        }
    });

    // 改造后 .route-main 不再是滚动容器，body / window 才是
    // header + content-top 都从 CSS 变量读取（避免硬编码，token 变化时 JS 自动跟上）
    var $scrollEl = $('html, body');
    var headerOffset = (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--shell-header-height')) || 64)
                    + (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--shell-content-top')) || 24);

    // plan-32:点二级 controller item → scroll 主区 + expand 对应 module + pin 高亮(防 IO 抢)
    // 一级 module group head 由 side-tree 接管(toggle 折叠/展开,不滚动)
    var pinnedUntil = 0;     // scroll-spy IO 钉住高亮的时间戳(同 config 套路)

    $sidebar.on('click', '.side-tree__item-link', function (e) {
        var $link = $(this);
        var moduleKey = $link.attr('data-module');
        var ctrl = $link.attr('data-controller');
        if (!moduleKey || !ctrl) return;
        e.preventDefault();
        var $module = $('#module-' + moduleKey);
        if ($module.length) {
            $module.removeClass('collapsed');
            $module.find('> .route-module-header').attr('aria-expanded', 'true');
        }
        var $target = $('#ctrl-' + moduleKey + '-' + ctrl);
        if ($target.length) {
            var top = $target.offset().top - headerOffset - 10;
            $scrollEl.animate({ scrollTop: top }, 300);
        }
        // 立刻高亮该 item + pin 800ms 让浏览器滚完锚点,scroll-spy 让位
        pinnedUntil = Date.now() + 800;
        $sidebar.find('.side-tree__item').removeClass('is-active');
        $link.closest('.side-tree__item').addClass('is-active');
        $sidebar.find('.side-tree__group').removeClass('is-active');
        var $g = $sidebar.find('.side-tree__group[data-group-key="' + moduleKey + '"]');
        $g.addClass('is-active').removeClass('is-collapsed');
    });

    // plan-29:行点击 → 弹 ACL 详情抽屉(忽略 row 内交互元素的 click)
    function openAclDrawer($row) {
        try {
            var route = $row.data('route');
            if (!route) return;
            // 同 controller 兄弟 actions:从 row 所属的 module-body 内扫同 controller_fqcn 的所有 route
            var siblings = [];
            var ctrl = route.controller_fqcn;
            $row.closest('.route-module-body').find('.route-row').each(function () {
                var sib = $(this).data('route');
                if (sib && sib.controller_fqcn === ctrl) siblings.push(sib);
            });
            window.dispatchEvent(new CustomEvent('set-route-detail', {
                detail: { route: route, siblings: siblings },
            }));
            window.dispatchEvent(new CustomEvent('open-drawer', {
                detail: { name: 'route-acl-detail', trigger: $row[0] },
            }));
        } catch (e) {
            console.warn('[route-acl-drawer] open failed:', e);
        }
    }

    $modules.on('click', '.route-row', function (e) {
        // 忽略行内 button / a / form 控件 — 不抢调试/文档按钮的点击
        if ($(e.target).closest('button, a, input, form, label').length) return;
        openAclDrawer($(this));
    });
    $modules.on('keydown', '.route-row', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32) {
            if ($(e.target).closest('button, a, input, form, label').length) return;
            e.preventDefault();
            openAclDrawer($(this));
        }
    });

    // plan-32:Scroll-spy(rAF 节流 + offsets 缓存)
    //   每帧找当前 scrollTop+viewport*0.35 之上最近的 .route-group-row,染对应 sidebar group + item .is-active
    //   IO 不适用 — ctrl-row 高 36px,active window 太窄抓不到(controller 间距 ~500px)
    //   group 加 .is-active 同时去 .is-collapsed(transient 自动展开,不动 Alpine state)
    //   pinnedUntil 800ms:点 sidebar 后让位,防 scroll handler 覆盖
    var $sidebarGroups = $sidebar.find('.side-tree__group');
    var $sidebarItems = $sidebar.find('.side-tree__item');
    var spyOffsets = null;
    var spyTicking = false;
    var lastSpyModule = null;
    var lastSpyCtrl = null;

    function buildSpyOffsets() {
        spyOffsets = [];
        $modules.find('.route-group-row').each(function () {
            // 跳过 search filter 隐藏的 row(display:none 时 offset().top 不可靠)
            if ($(this).css('display') === 'none') return;
            spyOffsets.push({
                module: $(this).attr('data-module'),
                ctrl: $(this).attr('data-ctrl-anchor'),
                top: $(this).offset().top,
            });
        });
    }

    function updateSpy() {
        spyTicking = false;
        if (Date.now() < pinnedUntil) return;
        if (!spyOffsets) buildSpyOffsets();
        var threshold = $(window).scrollTop() + window.innerHeight * 0.35;
        var currentModule = null;
        var currentCtrl = null;
        for (var i = 0; i < spyOffsets.length; i++) {
            if (spyOffsets[i].top <= threshold) {
                currentModule = spyOffsets[i].module;
                currentCtrl = spyOffsets[i].ctrl;
            } else {
                break;     // offsets 按 DOM 顺序,top 升序,提前 break
            }
        }
        if (currentModule !== lastSpyModule) {
            $sidebarGroups.removeClass('is-active');
            if (currentModule) {
                $sidebarGroups
                    .filter('[data-group-key="' + currentModule + '"]')
                    .addClass('is-active')
                    .removeClass('is-collapsed');
            }
            lastSpyModule = currentModule;
        }
        if (currentCtrl !== lastSpyCtrl) {
            $sidebarItems.removeClass('is-active');
            if (currentModule && currentCtrl) {
                // data-module / data-controller 在 <a>(透过 side-tree item.data 透传),
                // 不在 <li>;查 link 再 closest 到 .side-tree__item
                $sidebar
                    .find('.side-tree__item-link[data-module="' + currentModule + '"][data-controller="' + currentCtrl + '"]')
                    .closest('.side-tree__item')
                    .addClass('is-active');
            }
            lastSpyCtrl = currentCtrl;
        }
    }

    $(window).on('scroll', function () {
        if (!spyTicking) {
            spyTicking = true;
            window.requestAnimationFrame(updateSpy);
        }
    });
    $(window).on('resize load', function () { spyOffsets = null; });
    // 主区 filter / module collapse 变化也使缓存失效
    $search.on('input', function () { spyOffsets = null; });
    $whitelistFilter.on('change', function () { spyOffsets = null; });
    $('.route-module-header').on('click', function () { spyOffsets = null; });
});
