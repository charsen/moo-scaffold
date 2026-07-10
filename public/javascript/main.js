/**
 * Scaffold 全局脚本
 * 仅放跨页面共用逻辑：token / 401 / popstate / 侧栏 / 主题
 * 接口调试器专属逻辑见 pages/api-request.js
 */
(function ($) {
    // ===========================================================
    // 全局：按 host 存读 token + scoped cache key（暴露到 window）
    // ===========================================================
    var getHostTokens = function () {
        try { return JSON.parse(localStorage.getItem("scaffold_api_tokens") || "{}"); } catch (e) { return {}; }
    };
    var getHostToken = function (host) {
        return getHostTokens()[host] || "";
    };
    var getDebugClientId = function () {
        var key = "scaffold_debug_client_id";
        var clientId = localStorage.getItem(key);
        if (!clientId) {
            clientId = "client_" + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
            localStorage.setItem(key, clientId);
        }
        return clientId;
    };

    window.saveHostToken = function (host, token) {
        var tokens = getHostTokens();
        tokens[host] = token;
        localStorage.setItem("scaffold_api_tokens", JSON.stringify(tokens));
    };
    window.getDebugClientId = getDebugClientId;
    window.getDebugHostScope = function () {
        return ($("#host").val() || "").trim();
    };
    window.getScopedCacheKey = function () {
        var baseKey = $("#cache_key_base").val() || $("#cache_key").val() || "";
        if (!baseKey) return "";
        return [baseKey, window.getDebugHostScope(), getDebugClientId()].join("|");
    };
    window.restoreAuthToken = function () {
        var $el = $("#auth_token");
        if ($el.length === 0) return;
        var host = $("#host").val() || "";
        var token = getHostToken(host);
        $el.val(token ? "Bearer " + token : "");
    };

    // 按参数行的 send-key 路径从(可能嵌套的)数据对象取值。validKey 用 assignNestedValue 把
    // "items[0][name]" 这类 bracket-key 存成嵌套结构 {items:[{name:..}]},而历史回填 / JSON 编辑
    // 视图回填都按「行的 key」找值,直接 obj[key] 对嵌套 key 永远 undefined → bracket-key 参数回填
    // 全丢(2026-06-10 修)。单段 key(account / 平铺数组 up_personnel_id)走直接取,保持整存整取;
    // 多段才按路径下钻。返回 {found, value}。
    window.scaffoldResolvePathValue = function (obj, key) {
        if (obj == null || typeof obj !== "object" || !key) return { found: false };
        // 单段(无 [ 无 .)→ 直接取,平铺数组参数(单行整存)不被拆开
        if (key.indexOf("[") < 0 && key.indexOf(".") < 0) {
            return Object.prototype.hasOwnProperty.call(obj, key)
                ? { found: true, value: obj[key] }
                : { found: false };
        }
        var segs = key.match(/[^[\].]+/g) || [];
        var cur = obj;
        for (var i = 0; i < segs.length; i++) {
            if (cur == null || typeof cur !== "object") return { found: false };
            if (!Object.prototype.hasOwnProperty.call(cur, segs[i])) return { found: false };
            cur = cur[segs[i]];
        }
        return { found: true, value: cur };
    };

    // ===========================================================
    // DOM ready 后的全局事件
    // ===========================================================
    $(function () {
        // popstate 时整页刷新（解决 sidebar 状态与 URL 不同步）
        window.addEventListener("popstate", function () {
            window.location.reload();
        });

        // 401 → 跳登录
        $(document).ajaxError(function (event, xhr) {
            if (xhr.status === 401 && xhr.getResponseHeader("X-Scaffold-Auth") === "required") {
                var loginUrl = xhr.getResponseHeader("X-Scaffold-Login");
                if (loginUrl) {
                    window.location.href = loginUrl;
                }
            }
        });

        // 侧边栏树形 open/close（db / api / acl 用）
        $(".aside").on("click", "a", function () {
            $(this).parent().toggleClass("open");
        });

        // 主题切换（header 的 #theme_toggle）
        $("#theme_toggle").on("click", function () {
            var isDark = document.documentElement.getAttribute("data-theme") === "dark";
            if (isDark) {
                document.documentElement.removeAttribute("data-theme");
                localStorage.removeItem("scaffold_theme");
            } else {
                document.documentElement.setAttribute("data-theme", "dark");
                localStorage.setItem("scaffold_theme", "dark");
            }
        });

        // 侧栏拖拽调宽 + 记忆（2026-07-09，通用版：服务 aside / 路由 flex 栏 / 设计器 grid 栏）
        // 把手 position:fixed，left/top/height 由 JS 贴住目标侧栏实测右沿（跨三种布局稳）。
        // edge=viewport：var 直接取 clientX（aside，其 var = 预留区右沿 X）；
        // 否则 card 模式：var = clientX - 侧栏左沿（route/designer，其 var = 卡片本身宽度）。
        (function () {
            var root = document.documentElement;

            var initResizer = function (rz) {
                var target = document.getElementById(rz.getAttribute("data-resize-target"));
                if (!target) return;                                // 目标侧栏不在本页 → 跳过
                var VAR = rz.getAttribute("data-resize-var");
                var KEY = rz.getAttribute("data-resize-key");
                var MIN = parseInt(rz.getAttribute("data-resize-min"), 10) || 200;
                var MAXABS = parseInt(rz.getAttribute("data-resize-max"), 10) || 520;
                var DEF = parseInt(rz.getAttribute("data-resize-default"), 10) || 260;
                var edgeMode = rz.getAttribute("data-resize-edge") === "viewport";
                var maxW = function () { return Math.min(MAXABS, Math.round(window.innerWidth * 0.45)); };
                var dragging = false, raf = 0;

                // 把手贴住目标侧栏实测右沿（宽 0 → 该页无此栏，藏起来）
                var reposition = function () {
                    var r = target.getBoundingClientRect();
                    if (r.width <= 0) { rz.style.display = "none"; return; }
                    rz.style.display = "block";
                    rz.style.left = (r.right - 3) + "px";
                    rz.style.top = r.top + "px";
                    rz.style.height = r.height + "px";
                };
                var scheduleReposition = function () {
                    if (raf) return;
                    raf = requestAnimationFrame(function () { raf = 0; reposition(); });
                };

                var onMove = function (e) {
                    if (!dragging) return;
                    var origin = edgeMode ? 0 : target.getBoundingClientRect().left;
                    var w = Math.max(MIN, Math.min(maxW(), Math.round(e.clientX - origin)));
                    root.style.setProperty(VAR, w + "px");
                    reposition();
                    e.preventDefault();
                };
                var onUp = function () {
                    if (!dragging) return;
                    dragging = false;
                    document.body.classList.remove("is-resizing");
                    window.removeEventListener("pointermove", onMove);
                    window.removeEventListener("pointerup", onUp);
                    var cur = parseInt(root.style.getPropertyValue(VAR), 10);
                    if (cur >= MIN && cur <= MAXABS) localStorage.setItem(KEY, String(cur));
                };

                rz.addEventListener("pointerdown", function (e) {
                    if (e.button !== 0) return;                      // 仅左键
                    dragging = true;
                    document.body.classList.add("is-resizing");
                    window.addEventListener("pointermove", onMove);
                    window.addEventListener("pointerup", onUp);
                    e.preventDefault();
                });
                rz.addEventListener("dblclick", function () {        // 双击复位（清记忆）
                    root.style.setProperty(VAR, DEF + "px");
                    localStorage.removeItem(KEY);
                    reposition();
                });

                reposition();
                window.addEventListener("resize", scheduleReposition);
                window.addEventListener("scroll", scheduleReposition, true);   // 捕获:内层滚动也跟着重定位
            };

            var list = document.querySelectorAll(".side-resizer");
            for (var i = 0; i < list.length; i++) initResizer(list[i]);
        })();
    });

    // ===========================================================
    // 全局：剪贴板复制兼容辅助
    // navigator.clipboard 仅在 HTTPS / localhost 可用；HTTP 内网域名回落
    // textarea + execCommand。返回 Promise，方便调用方做 then/catch 反馈。
    // ===========================================================
    window.scaffoldCopyText = function (text) {
        text = String(text == null ? "" : text);
        if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () {
                return scaffoldFallbackCopy(text);
            });
        }
        return scaffoldFallbackCopy(text);
    };

    // ===========================================================
    // 全局：toast 通知 —— 桥接到 <x-scaffold::toast-container> (Alpine)
    // 用法：window.scaffoldToast('保存成功', 'success')
    // type: 'success' | 'error' | 'info' | 'warning' | 'danger' | 'neutral'
    //   ('error' 是 legacy 别名，自动映射为 'danger')
    // ===========================================================
    window.scaffoldToast = function (msg, type) {
        var tone = type || "info";
        if (tone === "error") tone = "danger";
        window.dispatchEvent(new CustomEvent("toast", {
            detail: { message: msg, tone: tone },
        }));
    };

    // ===========================================================
    // 全局：confirm Promise —— 替代浏览器原生 confirm()，与 <x-scaffold::confirm-container> 联动
    // 用法：window.scaffoldConfirm({ message:'...', danger:true }).then(ok => { if (ok) ... })
    //   或简写：window.scaffoldConfirm('确认删除？').then(ok => ...)
    // ===========================================================
    window.scaffoldConfirm = function (msgOrOpts) {
        var opts = typeof msgOrOpts === "string" ? { message: msgOrOpts } : (msgOrOpts || {});
        return new Promise(function (resolve) {
            opts.resolve = resolve;
            window.dispatchEvent(new CustomEvent("scaffold-confirm", { detail: opts }));
        });
    };

    // ===========================================================
    // 全局：form[data-confirm] 表单二次确认（替代 inline onsubmit，CSP 友好；
    // 走 <x-scaffold::confirm-container> 与 toast 同套视觉。jQuery 委托。）
    // ===========================================================
    if (window.jQuery) {
        jQuery(function ($) {
            $(document.body).on("submit", "form[data-confirm]", function (e) {
                var $form = $(this);
                var msg = $form.attr("data-confirm");
                if (!msg) return;
                if ($form.data("confirmed")) return;     // 二次提交放行，让 page-scoped 的 ajax handler 跑
                e.preventDefault();
                e.stopImmediatePropagation();            // 阻止同元素其他 submit handler 同时跑
                // plan-22 Q4: 透传 form 上的 data-challenge / data-challenge-label(单条 purge 输 hash 前 8 位,批量输"清除 N 条")
                var opts = {
                    message: msg,
                    danger: /删除|清空|彻底|purge|清除/.test(msg),
                };
                var ch = $form.attr("data-challenge");
                if (ch) {
                    opts.challenge = ch;
                    opts.challengeLabel = $form.attr("data-challenge-label") || ("请输入 " + ch + " 确认");
                }
                window.scaffoldConfirm(opts).then(function (ok) {
                    if (ok) {
                        $form.data("confirmed", true);
                        $form.trigger("submit");
                    }
                });
            });
        });
    }

    // ===========================================================
    // plan-22 T5: 全局 Cmd/Ctrl+S 拦截
    //   - Designer 自己的 listener 在 alpine-init.js:608 已 preventDefault,本拦截不冲突(stopImmediatePropagation 先到的赢)
    //   - 优先顺序:1) [data-shortcut="save"] 元素 → 2) document.activeElement 所在 form → 3) toast 提示
    // ===========================================================
    if (window.jQuery) {
        jQuery(function ($) {
            $(document).on('keydown', function (e) {
                var isMeta = e.metaKey || e.ctrlKey;
                if (!isMeta) return;
                if (e.key !== 's' && e.key !== 'S') return;

                // 让 designer 的 Alpine listener 优先(alpine-init.js dbDesigner 自己已 preventDefault + saveNow)
                // 注意:Alpine 注册名是 dbDesigner 不是 designer
                if (document.querySelector('[x-data="dbDesigner"], [x-data^="dbDesigner"]')) return;

                e.preventDefault();

                // 1) 优先 [data-shortcut="save"] 按钮
                var saveBtn = document.querySelector('[data-shortcut="save"]');
                if (saveBtn) {
                    if (saveBtn.tagName === 'FORM') saveBtn.requestSubmit ? saveBtn.requestSubmit() : saveBtn.submit();
                    else saveBtn.click();
                    return;
                }

                // 2) 当前 focused 元素所在 form 的 submit 按钮
                var ae = document.activeElement;
                if (ae && ae.form) {
                    var primary = ae.form.querySelector('button[type="submit"], input[type="submit"]');
                    if (primary) { primary.click(); return; }
                }

                // 3) 整页第一个 form 的 submit(单一表单页常见,例如 config 某组)
                var anyForm = document.querySelector('form[method="POST"], form[method="post"]');
                if (anyForm) {
                    var sub = anyForm.querySelector('button[type="submit"], input[type="submit"]');
                    if (sub) { sub.click(); return; }
                }

                // 4) 找不到:toast 提示
                if (window.scaffoldToast) {
                    // scaffoldToast(msg, type) 收字符串 + tone,早先误传了对象 → 渲染成 [object Object](2026-06-09 修)
                    window.scaffoldToast('当前页无可保存操作', 'neutral');
                }
            });
        });
    }

    function scaffoldFallbackCopy(text) {
        return new Promise(function (resolve, reject) {
            var ta = document.createElement("textarea");
            ta.value = text;
            ta.setAttribute("readonly", "");
            ta.style.position = "fixed";
            ta.style.top = "0";
            ta.style.left = "0";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, ta.value.length);
            var ok = false;
            try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            ok ? resolve() : reject(new Error("copy failed"));
        });
    }
})(jQuery);
