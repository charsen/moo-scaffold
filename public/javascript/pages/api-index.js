// ==============================================================================
// 页面级脚本: api/index 接口文档浏览
// 消费 view: api/index.blade.php
// 配置注入: window.ScaffoldApiIndex = {
//     currentAppName: string,
//     apiShowUrl: string,
//     initial: { folder, controller, action, apiName, moduleName } | null
// }
// 依赖: jQuery
// ==============================================================================

(function ($) {
    var cfg = window.ScaffoldApiIndex || {};
    var rightEmptyHtml = '';

    function setRightEmptyState() {
        $('#right_container').addClass('transparent').html(rightEmptyHtml);
    }

    function getDoc(folder, controller, action, apiName, moduleName) {
        document.title = (cfg.currentAppName || '')
                       + ' - '
                       + (moduleName || '')
                       + ' - '
                       + (apiName || action);

        $.ajax({
            type: 'GET',
            url: cfg.apiShowUrl,
            data: { app: cfg.currentApp, f: folder, c: controller, a: action },
            dataType: 'html',
            success: function (result) {
                $('#right_container').removeClass('transparent').html(result);
            },
            error: function () {
                // 加载失败:图标 + 文案 + 重试按钮(重试直接重发本次 getDoc,不用回左栏重点接口)
                var $fail = $(
                    '<div class="api-load-fail">' +
                    '<svg class="api-load-fail__icon" xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                    '<p class="api-load-fail__text">文档加载失败</p>' +
                    '<button type="button" class="api-load-fail__retry">重试</button>' +
                    '</div>'
                );
                $fail.find('.api-load-fail__retry').on('click', function () {
                    $('#right_container').html('<p class="p-api-doc__loading">Loading api document...</p>');
                    getDoc(folder, controller, action, apiName, moduleName);
                });
                $('#right_container').removeClass('transparent').empty().append($fail);
            }
        });
    }

    $(function () {
        rightEmptyHtml = $('#right_container').html();
        $('#right_container').removeClass('transparent');

        // plan-27:sideTree 用 .side-tree__item-link + .is-active(原 a.link + .active)
        $('#aside_container').on('click', '.side-tree__item-link', function (e) {
            var $link = $(this);
            // 只处理带 data-url 的 sidebar leaf(group head 不带,自动跳过)
            if (! $link.data('url')) return;
            e.preventDefault();
            window.history.pushState({}, 0, $link.data('url'));
            $('#aside_container .side-tree__item.is-active').removeClass('is-active');
            $link.parent().addClass('is-active');

            $('#right_container').removeClass('transparent').html('<p class="p-api-doc__loading">Loading api document...</p>');

            getDoc(
                $link.data('f'),
                $link.data('c'),
                $link.data('a'),
                $link.data('api-name'),
                $link.data('module')
            );
        });

        if (cfg.initial && cfg.initial.folder && cfg.initial.controller && cfg.initial.action) {
            getDoc(
                cfg.initial.folder,
                cfg.initial.controller,
                cfg.initial.action,
                cfg.initial.apiName,
                cfg.initial.moduleName
            );
        } else {
            setRightEmptyState();
        }
    });
})(jQuery);
