/**
 * API 调试器 (/scaffold/api/request) 专属脚本
 *
 * 依赖：
 *   - jQuery 3.x（main.js 之后）
 *   - main.js（提供 window.saveHostToken / restoreAuthToken / getScopedCacheKey 等）
 *   - jsonFormat.js（提供 Process() 全局函数）
 *   - clipboard.min.js（提供 ClipboardJS 构造）
 *   - window.ScaffoldConfig.apiCache / .apiProxy 由 view 注入
 */
(function ($) {
    $(function () {
        var saveHostToken = window.saveHostToken;
        var SC = window.ScaffoldConfig || {};

        // ------- 参数说明 即时 tooltip(名称右侧 ⓘ hover)-------
        // 说明列已删,填写提示/验证约束放 .api-debug-hint-icon[data-hint];hover 即时弹气泡。
        // 气泡挂 <body> + position:fixed,不被参数表/面板的 overflow:hidden 裁切。
        var $hintPop = null;
        var showHint = function (icon) {
            var hint = icon.getAttribute("data-hint");
            if (!hint) return;
            if (!$hintPop) {
                $hintPop = $('<div class="api-debug-hint-pop" role="tooltip"></div>').appendTo("body");
            }
            var r = icon.getBoundingClientRect();
            $hintPop.text(hint).css({ display: "block", visibility: "hidden", left: "0px", top: "0px" });
            var pw = $hintPop.outerWidth(), ph = $hintPop.outerHeight();
            var left = Math.min(Math.max(8, r.left + r.width / 2 - pw / 2), window.innerWidth - pw - 8);
            var top = r.top - ph - 8;
            if (top < 8) top = r.bottom + 8;
            $hintPop.css({ left: left + "px", top: top + "px", visibility: "visible" });
        };
        var hideHint = function () { if ($hintPop) $hintPop.css("display", "none"); };
        $("body").on("mouseenter focusin", ".api-debug-hint-icon", function () { showHint(this); });
        $("body").on("mouseleave focusout", ".api-debug-hint-icon", function () { hideHint(); });

        // ------- method 着色：同步 toolbar data-method 到当前 select 值 -------
        $("body").on("change", "#send_method", function () {
            $(this).closest(".debug-request-toolbar").attr("data-method", $(this).val());
        });

        // ------- 新增空行（Headers） -------
        $("body").on("click", ".api-debug-add-row", function () {
            var target = $(this).data("target");
            var cols = parseInt($(this).data("cols"), 10) || 4;
            var $table = $(target).find("table").first();
            if (!$table.length) return;

            var $tr = $('<tr></tr>');
            $tr.append($('<td><input type="checkbox" class="checkbox" checked /></td>'));
            $tr.append($('<td><input type="text" class="txt debug-param-plain" placeholder="名称" /></td>'));
            $tr.append($('<td><input type="text" class="txt key" placeholder="key" /></td>'));
            $tr.append($('<td><input type="text" class="txt value" placeholder="value" /></td>'));
            for (var i = 4; i < cols; i++) {
                $tr.append($('<td><input type="text" class="txt debug-param-plain debug-param-desc" placeholder="说明" /></td>'));
            }
            $table.append($tr);
            $tr.find('input[type="text"]').eq(1).trigger('focus');
        });

        // ------- 全选 / 单选同步 -------
        // 排除 :disabled —— 不可发送(sendable=false)的参数 checkbox 是 disabled,原先全选
        // 无差别 this.checked=true 把它们也勾上,validKey 的 .checkbox:checked 会把这些不该发的
        // 参数(route 解析值等)一并塞进请求;同步计数也得只数可用项,否则 disabled 行永不勾 →
        // 全选态永远点不亮(2026-06-10 修)。
        $("body").on("change", ".checkbox-all", function () {
            var checked = this.checked;
            $(this).parents("table").find(".checkbox:not(:disabled)").each(function () {
                this.checked = checked;
            });
        });

        $("body").on("change", ".checkbox", function () {
            var $table = $(this).parents("table");
            var $all = $table.find(".checkbox-all");
            if (!$all.length) return;
            // 同步「全选」态:全勾→勾上,取消任一→取消(原来只会取消、漏了回勾);只数 enabled
            var total = $table.find(".checkbox:not(:disabled)").length;
            var checked = $table.find(".checkbox:not(:disabled):checked").length;
            $all.prop("checked", total > 0 && checked === total);
        });

        // ------- 参数收集（用于缓存与发送） -------
        var collectAllParams = function (el) {
            var obj = {};
            $(el).find("tr").each(function () {
                var key = $(this).find(".cache-key").val() || $(this).find(".key").val(),
                    value = $(this).find(".value").val(),
                    checked = $(this).find(".checkbox").is(":checked");
                if (key && key !== "" && value !== "") {
                    obj[key] = { value: value, checked: checked };
                }
            });
            return obj;
        };

        var parseStructuredKey = function (key) {
            if (!key) return [];
            if (key.indexOf("[") > -1) {
                var bracketSegments = key.match(/([^[\]]+)/g);
                return bracketSegments || [];
            }
            return key.split(".").map(function (segment) {
                return segment === "*" ? "0" : segment;
            });
        };

        // 大整数精度保护:JSON.parse 把超过 Number.MAX_SAFE_INTEGER(9007199254740991)
        // 的整数(如雪花 ID 621331468437819392)按 IEEE-754 double 截断成 ...819400。
        // 解析前把"值位置"的纯整数(排除小数/科学计数)且超安全范围的,先包成字符串,
        // JSON.parse 得到字符串原样转发后端 → 目标接口收到精确值(其本就回显成字符串)。
        // 字符串字面量整体先吃掉,避免改到字符串里的数字。
        var preserveBigInts = function (jsonText) {
            return jsonText.replace(
                /("(?:\\.|[^"\\])*")|(-?\d+)(?![\d.eE])/g,
                function (match, stringLiteral, intLiteral) {
                    if (stringLiteral !== undefined) return stringLiteral;
                    return Number.isSafeInteger(Number(intLiteral)) ? intLiteral : '"' + intLiteral + '"';
                }
            );
        };

        var parseParamValue = function (value, splitComma) {
            if (typeof value !== "string") return value;
            var trimmed = $.trim(value);
            if (trimmed === "") return value;

            if ((trimmed[0] === "[" && trimmed.slice(-1) === "]") ||
                (trimmed[0] === "{" && trimmed.slice(-1) === "}")) {
                try { return JSON.parse(preserveBigInts(trimmed)); } catch (e) { return value; }
            }
            if (splitComma && trimmed.indexOf(",") > -1) {
                return trimmed.split(",").map(function (item) { return $.trim(item); });
            }
            return value;
        };

        var assignNestedValue = function (target, key, value) {
            var segments = parseStructuredKey(key);
            if (!segments.length) return;
            var current = target;
            for (var i = 0; i < segments.length; i++) {
                var segment = segments[i];
                var isLast = i === segments.length - 1;
                var nextSegment = segments[i + 1];
                var nextIsIndex = nextSegment !== undefined && /^\d+$/.test(nextSegment);
                var currentKey = /^\d+$/.test(segment) ? parseInt(segment, 10) : segment;

                if (isLast) {
                    current[currentKey] = value;
                    return;
                }
                if (current[currentKey] === undefined || current[currentKey] === null || typeof current[currentKey] !== "object") {
                    current[currentKey] = nextIsIndex ? [] : {};
                }
                current = current[currentKey];
            }
        };

        var validKey = function (el) {
            var obj = {},
                splitComma = el === "#request_params";
            $(el).find(".checkbox:checked").each(function () {
                var $tr = $(this).parents("tr"),
                    key = $tr.find(".send-key").val() || $tr.find(".key").val(),
                    value = parseParamValue($tr.find(".value").val(), splitComma);
                if (!key) return;
                assignNestedValue(obj, key, value);
            });
            return obj;
        };

        // ------- 缓存 -------
        var cacheParams = function (uri) {
            var cache_key = window.getScopedCacheKey();
            var allParams = $.extend({}, collectAllParams("#request_params"), collectAllParams("#request_body_params"));
            if (!cache_key || $.isEmptyObject(allParams)) return;
            $.ajax({ url: SC.apiCache, type: "post", data: { uri: uri, key: cache_key, params: allParams } });
        };

        // =========================================================================
        // form_widgets 表单预览 (2026-05-22)
        // shape-based detection 命中后,响应面板第 3 个 tab 启用,渲染可操作表单
        // 用于"调创建 / 编辑接口拿到 widget 配置,验证数据正常 + 控件能操作",
        // 不追求视觉跟生产一致
        // =========================================================================

        // 已知 widget type 白名单(对齐 下游 admin 前端 former/config.ts 的 elComponents 注册表)
        // 2026-06-10:补 'rate-picker'(former 新增,a-rate 封装,带 max/allow-half/tooltips)。
        var KNOWN_WIDGET_TYPES = [
            'input', 'textarea', 'password', 'radio', 'select', 'cascader',
            'date-picker', 'datetime-picker', 'month-picker', 'month-day-picker',
            'date', 'editor', 'upload-image', 'upload-file', 'cropper-image',
            'checkbox', 'color-picker', 'rate', 'rate-picker'
        ];

        // shape detection:顶层是 array,叶子对象有 type(string)+ label(string),且整组里
        // 至少有一个 widget 的 type 命中白名单。
        function looksLikeFormWidgets(data) {
            if (!Array.isArray(data) || data.length === 0) return false;
            // 拍平 一维 [widget, ...] 和二维 [[widget,...], ...] 成一维 widget 列表
            var widgets = Array.isArray(data[0])
                ? data.reduce(function (acc, row) { return acc.concat(Array.isArray(row) ? row : [row]); }, [])
                : data;
            var sample = widgets[0];
            // shape gate:首个叶子必须是带 string type + string label 的对象
            if (!sample || typeof sample !== 'object') return false;
            if (typeof sample.type !== 'string') return false;
            if (typeof sample.label !== 'string') return false;
            // 白名单守护:不硬卡"首个" widget 的 type —— 真实 former 对未知 type 回退成 input
            // (former config.ts: components[widget.type] ?? components.input),首字段恰是
            // 未知/新增/误用的 type(如 host 把只读展示字段写成 'text')时,不该让整张表单预览
            // 静默消失。改成"任一 widget 命中白名单"即认定为表单:兼容首字段未知,又挡住任意数组误判。
            return widgets.some(function (w) {
                return w && typeof w.type === 'string' && KNOWN_WIDGET_TYPES.indexOf(w.type) > -1;
            });
        }

        // 派生:把任意一维/二维结构 normalize 成二维 [[widget,...], ...]
        function normalizeWidgetMatrix(data) {
            if (!Array.isArray(data) || data.length === 0) return [];
            if (Array.isArray(data[0])) return data;
            // 一维 → 每个 widget 单独一行
            return data.map(function (w) { return [w]; });
        }

        // 当前预览状态(每次响应回来重置)
        var formPreviewState = {
            matrix: [],          // 二维 widget 数组
            values: {},          // 表单值,key 用 widget.field / label / __idx
            cascaderOpenId: null
        };

        // 2026-05-23 user 反馈 bug:切换接口时上一接口的表单预览残留 — 导出清理函数,
        // api-request-index.js 的 getParams 在 #left_container 重渲后调一下,把 DOM + state 一起清。
        window.scaffoldClearFormPreview = function () {
            formPreviewState.matrix = [];
            formPreviewState.values = {};
            formPreviewState.cascaderOpenId = null;
            $('#form_preview_tab').attr('hidden', 'hidden').removeClass('is-active');
            $('#form_preview_main').empty();
            $('#form_preview_output').text('{}');
            $('.api-debug-response-pane[data-pane="form-preview"]').removeClass('is-active');
        };

        // value key 派生:优先 widget.field(backend toArray 已带过来),
        // fallback label,再 fallback __idx_r_c
        function deriveValueKey(widget, rowIdx, colIdx, usedKeys) {
            var field = (widget.field || '').trim();
            if (field && usedKeys.indexOf(field) < 0) {
                usedKeys.push(field);
                return field;
            }
            var label = (widget.label || '').trim();
            if (label && usedKeys.indexOf(label) < 0) {
                usedKeys.push(label);
                return label;
            }
            return '__idx_' + rowIdx + '_' + colIdx;
        }

        // rules normalize:[{rule, msg}] / ['required', ...] / 混合 → 字符串数组
        function normalizeRules(rules) {
            if (!Array.isArray(rules)) return [];
            return rules.map(function (r) {
                if (typeof r === 'string') return r;
                if (r && typeof r === 'object') {
                    if (r.rule) return r.rule;
                    if (r.name) return r.name;
                }
                try { return JSON.stringify(r); } catch (e) { return String(r); }
            }).filter(function (s) { return !!s; });
        }

        // ------- value 写入 + JSON 输出实时刷新 -------
        function setFormPreviewValue(key, value) {
            if (value === '' || value === null || value === undefined) {
                delete formPreviewState.values[key];
            } else {
                formPreviewState.values[key] = value;
            }
            refreshFormPreviewOutput();
        }

        function refreshFormPreviewOutput() {
            var out = document.getElementById('form_preview_output');
            if (!out) return;
            try {
                out.textContent = JSON.stringify(formPreviewState.values, null, 2);
            } catch (e) {
                out.textContent = '(JSON serialize failed)';
            }
        }

        // ------- 单个 widget 渲染 -------
        function renderWidget(widget, valueKey) {
            var $widget = $('<div class="api-debug-form-preview__widget"></div>');
            if (widget.hidden) $widget.addClass('is-hidden');
            if (widget.disabled) $widget.addClass('is-disabled');

            // ---- label 行 ----
            var $labelRow = $('<div class="api-debug-form-preview__label-row"></div>');
            $labelRow.append($('<span class="api-debug-form-preview__label"></span>').text(widget.label || '（无 label）'));
            // 2026-05-23 user 反馈:label 后跟原始英文 field 名,联调时一眼看到对应的 API key
            // (后端 toArray 已带 field 过来,跟 widget.label 中文名/i18n 区分)。
            if (widget.field) {
                $labelRow.append($('<code class="api-debug-form-preview__field-name" title="原始 field key"></code>').text(widget.field));
            }
            if (widget.required) {
                $labelRow.append('<span class="api-debug-form-preview__required" title="必填">*</span>');
            }
            $labelRow.append($('<span class="api-debug-form-preview__chip api-debug-form-preview__chip--type"></span>').text(widget.type));
            if (widget.hidden) {
                $labelRow.append('<span class="api-debug-form-preview__chip api-debug-form-preview__chip--hidden" title="生产环境隐藏此字段">🙈 HIDDEN</span>');
            }
            if (widget.disabled) {
                $labelRow.append('<span class="api-debug-form-preview__chip api-debug-form-preview__chip--disabled" title="生产环境此字段不可编辑">🔒 DISABLED</span>');
            }
            $widget.append($labelRow);

            // ---- rules hint ----
            var rulesStrs = normalizeRules(widget.rules);
            if (rulesStrs.length) {
                $widget.append($('<p class="api-debug-form-preview__rules"></p>').text('rules: ' + rulesStrs.join(' | ')));
            }

            // ---- control ----
            // 2026-05-23 user 反馈:hidden 字段在生产被隐藏不渲染,联调时虽然显示但应该禁止编辑
            // (避免 user 误填一个 prod 根本不接收的字段)。统一并入 disabled 语义 → renderControl
            // 里所有 widget.disabled gate 自动覆盖。chip 已在 label 行单独渲染,这里设
            // 不会影响视觉(HIDDEN chip 已在上面,不会重复 DISABLED chip)
            if (widget.hidden) widget.disabled = true;
            var $ctrl = $('<div class="api-debug-form-preview__control"></div>');
            renderControl($ctrl, widget, valueKey);
            $widget.append($ctrl);

            // ---- tip ----
            if (widget.tip) {
                $widget.append($('<p class="api-debug-form-preview__tip"></p>').text(widget.tip));
            }

            // 初始 default 写入 values
            if (widget.default !== undefined && widget.default !== null && widget.default !== '') {
                formPreviewState.values[valueKey] = widget.default;
            }

            return $widget;
        }

        function renderControl($ctrl, widget, valueKey) {
            var type = widget.type;
            switch (type) {
                case 'input':
                    return renderInputCtrl($ctrl, widget, valueKey, 'text');
                case 'password':
                    return renderInputCtrl($ctrl, widget, valueKey, 'password');
                case 'date':
                case 'date-picker':
                    return renderInputCtrl($ctrl, widget, valueKey, 'date');
                case 'datetime-picker':
                    return renderInputCtrl($ctrl, widget, valueKey, 'datetime-local');
                case 'month-picker':
                    return renderInputCtrl($ctrl, widget, valueKey, 'month');
                case 'month-day-picker':
                    return renderInputCtrl($ctrl, widget, valueKey, 'text');
                case 'color-picker':
                    return renderInputCtrl($ctrl, widget, valueKey, 'color');
                case 'rate':
                case 'rate-picker':
                    // former 的 rate-picker 是 a-rate(星级)封装,值是 0..max 的 number;
                    // 预览用数字输入框近似(跟 rate 一致,调试只需能填值验证请求)。
                    return renderRateCtrl($ctrl, widget, valueKey);
                case 'textarea':
                    return renderTextareaCtrl($ctrl, widget, valueKey);
                case 'editor':
                    return renderEditorCtrl($ctrl, widget, valueKey);
                case 'radio':
                    return renderRadioCtrl($ctrl, widget, valueKey);
                case 'checkbox':
                    return renderCheckboxCtrl($ctrl, widget, valueKey);
                case 'select':
                    return renderSelectCtrl($ctrl, widget, valueKey);
                case 'cascader':
                    return renderCascader($ctrl, widget, valueKey);
                case 'upload-image':
                case 'cropper-image':
                case 'upload-file':
                    return renderUploadCtrl($ctrl, widget, valueKey);
                default:
                    return $ctrl.append($('<div class="api-debug-form-preview__placeholder-box"></div>').text('未知 widget 类型：' + type));
            }
        }

        function renderInputCtrl($ctrl, widget, valueKey, inputType) {
            var $input = $('<input class="api-debug-form-preview__input" />');
            $input.attr('type', inputType);
            if (widget.placeholder) $input.attr('placeholder', widget.placeholder);
            if (widget.disabled) $input.prop('disabled', true);
            if (widget.default !== undefined && widget.default !== null && widget.default !== '') {
                $input.val(widget.default);
            }
            $input.on('input change', function () {
                if (widget.disabled) return;
                setFormPreviewValue(valueKey, $(this).val());
            });
            // 日期 / 颜色 等原生 picker 类:点 input 任何位置都弹 picker(默认要点右侧图标)
            // showPicker() 标准 API,Chromium 99+ / Firefox 101+ / Safari 16+
            var pickerTypes = ['date', 'datetime-local', 'month', 'week', 'time', 'color'];
            if (pickerTypes.indexOf(inputType) >= 0) {
                $input.on('click focus', function () {
                    if (widget.disabled) return;
                    if (typeof this.showPicker === 'function') {
                        try { this.showPicker(); } catch (e) { /* user-gesture 限制时静默 */ }
                    }
                });
            }
            $ctrl.append($input);
        }

        // rate / rate-picker:former RatePicker 的值是 0..max 的 number。预览用 number 输入近似,
        // 对齐 max(props.max ?? count,默认 5)+ allow-half(step 0.5)。
        function renderRateCtrl($ctrl, widget, valueKey) {
            var max = parseInt(widget.max != null ? widget.max : (widget.count != null ? widget.count : 5), 10);
            if (!(max > 0)) max = 5;
            var half = !!(widget.allow_half || widget.allowHalf);
            var $input = $('<input class="api-debug-form-preview__input" type="number" />');
            $input.attr('min', '0').attr('max', String(max)).attr('step', half ? '0.5' : '1');
            if (widget.placeholder) $input.attr('placeholder', widget.placeholder);
            if (widget.disabled) $input.prop('disabled', true);
            if (widget.default !== undefined && widget.default !== null && widget.default !== '') {
                $input.val(widget.default);
            }
            $input.on('input change', function () {
                if (widget.disabled) return;
                setFormPreviewValue(valueKey, $(this).val());
            });
            $ctrl.append($input);
            $ctrl.append($('<p class="api-debug-form-preview__tip"></p>')
                .text('★ 评分 0–' + max + (half ? '（半星 step 0.5）' : '')));
        }

        function renderTextareaCtrl($ctrl, widget, valueKey) {
            var $ta = $('<textarea class="api-debug-form-preview__textarea" rows="3"></textarea>');
            if (widget.placeholder) $ta.attr('placeholder', widget.placeholder);
            if (widget.disabled) $ta.prop('disabled', true);
            if (widget.default) $ta.val(widget.default);
            $ta.on('input change', function () {
                if (widget.disabled) return;
                setFormPreviewValue(valueKey, $(this).val());
            });
            $ctrl.append($ta);
        }

        function renderEditorCtrl($ctrl, widget, valueKey) {
            renderTextareaCtrl($ctrl, widget, valueKey);
            $ctrl.append($('<p class="api-debug-form-preview__tip"></p>').text('（富文本预览不复刻，以 textarea 占位）'));
        }

        function renderRadioCtrl($ctrl, widget, valueKey) {
            var $group = $('<div class="api-debug-form-preview__radio-group"></div>');
            var opts = Array.isArray(widget.options) ? widget.options : [];
            var groupName = 'fp_radio_' + valueKey.replace(/[^a-z0-9_]/gi, '_');
            opts.forEach(function (opt) {
                var lab = (opt && (opt.label !== undefined ? opt.label : opt.name)) || '';
                var val = (opt && (opt.value !== undefined ? opt.value : (opt.id !== undefined ? opt.id : lab)));
                var $label = $('<label class="api-debug-form-preview__radio-item"></label>');
                var $input = $('<input type="radio" />').attr('name', groupName).val(String(val));
                if (widget.disabled) $input.prop('disabled', true);
                if (String(widget.default) === String(val)) $input.prop('checked', true);
                $input.on('change', function () {
                    if (widget.disabled) return;
                    if (this.checked) setFormPreviewValue(valueKey, val);
                });
                $label.append($input).append($('<span></span>').text(String(lab)));
                $group.append($label);
            });
            $ctrl.append($group);
        }

        function renderCheckboxCtrl($ctrl, widget, valueKey) {
            var $group = $('<div class="api-debug-form-preview__radio-group"></div>');
            var opts = Array.isArray(widget.options) ? widget.options : [];
            opts.forEach(function (opt) {
                var lab = (opt && (opt.label !== undefined ? opt.label : opt.name)) || '';
                var val = (opt && (opt.value !== undefined ? opt.value : (opt.id !== undefined ? opt.id : lab)));
                var $label = $('<label class="api-debug-form-preview__radio-item"></label>');
                var $input = $('<input type="checkbox" />').val(String(val));
                if (widget.disabled) $input.prop('disabled', true);
                $input.on('change', function () {
                    if (widget.disabled) return;
                    var current = formPreviewState.values[valueKey];
                    if (!Array.isArray(current)) current = [];
                    if (this.checked) {
                        if (current.indexOf(val) < 0) current.push(val);
                    } else {
                        current = current.filter(function (v) { return v !== val; });
                    }
                    setFormPreviewValue(valueKey, current.length ? current : '');
                });
                $label.append($input).append($('<span></span>').text(String(lab)));
                $group.append($label);
            });
            $ctrl.append($group);
        }

        // options 归一(对齐 former MSelect::normalizeOption):
        //   - array 或 object-map(MSelect 用 Object.values 兼容)都接受
        //   - grouped(opt.options / opt.children 嵌套)拍平成可选项,label 带 "组 / 子项" 前缀
        // 原实现只认 array + 平铺 → map 形态渲染空、grouped 渲染成一个无值的坏项(2026-06-10 对齐)。
        function flattenSelectOptions(rawOptions) {
            var list = [];
            if (Array.isArray(rawOptions)) {
                list = rawOptions;
            } else if (rawOptions && typeof rawOptions === 'object') {
                list = Object.keys(rawOptions).map(function (k) { return rawOptions[k]; });
            }
            var out = [];
            function walk(opt, groupLabel) {
                if (opt === null || opt === undefined) return;
                if (typeof opt !== 'object') { out.push({ label: String(opt), value: opt }); return; }
                var children = Array.isArray(opt.options) ? opt.options
                    : (Array.isArray(opt.children) ? opt.children : null);
                if (children) {
                    var gl = opt.label !== undefined ? String(opt.label) : '';
                    children.forEach(function (c) { walk(c, gl); });
                    return;
                }
                var lab = opt.label !== undefined ? opt.label : (opt.name !== undefined ? opt.name : '');
                var val = opt.value !== undefined ? opt.value : (opt.id !== undefined ? opt.id : lab);
                out.push({ label: (groupLabel ? groupLabel + ' / ' : '') + String(lab), value: val });
            }
            list.forEach(function (o) { walk(o, ''); });
            return out;
        }

        // antd 风格 select dropdown:trigger + panel + 单/多选
        function renderSelectCtrl($ctrl, widget, valueKey) {
            var normOpts = flattenSelectOptions(widget.options);

            var $wrap = $('<div class="api-debug-form-preview__select-dd"></div>');
            if (widget.multiple) $wrap.addClass('is-multiple');
            var $trigger = $('<button type="button" class="api-debug-form-preview__select-dd-trigger is-empty"></button>');
            $trigger.text(widget.placeholder || '请选择');
            if (widget.disabled) $trigger.prop('disabled', true);
            var $panel = $('<div class="api-debug-form-preview__select-dd-panel" hidden></div>');

            // state:多选 selected=[val, val] / 单选 selected=val|null
            var state = { open: false, selected: widget.multiple ? [] : null };

            // 初始 default
            if (widget.default !== undefined && widget.default !== null && widget.default !== '') {
                if (widget.multiple) {
                    state.selected = Array.isArray(widget.default) ? widget.default.slice() : [widget.default];
                } else {
                    state.selected = widget.default;
                }
            }

            function renderTrigger() {
                $trigger.empty();
                if (widget.multiple) {
                    if (!state.selected.length) {
                        $trigger.addClass('is-empty').text(widget.placeholder || '请选择');
                        return;
                    }
                    $trigger.removeClass('is-empty');
                    state.selected.forEach(function (v) {
                        var matched = normOpts.find(function (o) { return o.value == v; });
                        var lab = matched ? matched.label : String(v);
                        var $chip = $('<span class="api-debug-form-preview__cascader-chip"></span>')
                            .append($('<span class="api-debug-form-preview__cascader-chip-text"></span>').text(lab));
                        if (!matched) $chip.addClass('api-debug-form-preview__cascader-chip--missing').empty()
                            .text('⚠ ' + String(v));
                        if (matched) {
                            var $del = $('<span class="api-debug-form-preview__cascader-chip-del" aria-label="移除"></span>').text('×');
                            $del.on('click', function (e) {
                                e.stopPropagation();
                                if (widget.disabled) return;
                                state.selected = state.selected.filter(function (x) { return x != v; });
                                renderTrigger();
                                commit();
                                if (state.open) renderPanel();
                            });
                            $chip.append($del);
                        }
                        $trigger.append($chip);
                    });
                } else {
                    if (state.selected == null || state.selected === '') {
                        $trigger.addClass('is-empty').text(widget.placeholder || '请选择');
                        return;
                    }
                    var matched = normOpts.find(function (o) { return o.value == state.selected; });
                    if (matched) {
                        $trigger.removeClass('is-empty').text(matched.label);
                    } else {
                        $trigger.removeClass('is-empty').text('⚠ ' + String(state.selected) + ' （不在 options 内）');
                    }
                }
            }

            function renderPanel() {
                $panel.empty();
                normOpts.forEach(function (o) {
                    var $item = $('<div class="api-debug-form-preview__select-dd-item"></div>').text(o.label);
                    var isSelected = widget.multiple
                        ? state.selected.some(function (v) { return v == o.value; })
                        : state.selected == o.value;
                    if (isSelected) $item.addClass('is-selected');
                    $item.on('click', function (e) {
                        e.stopPropagation();
                        if (widget.disabled) return;
                        if (widget.multiple) {
                            // 不重渲整 panel(避免 item DOM 失效),只 toggle is-selected
                            var idx = state.selected.findIndex(function (v) { return v == o.value; });
                            if (idx >= 0) {
                                state.selected.splice(idx, 1);
                                $item.removeClass('is-selected');
                            } else {
                                state.selected.push(o.value);
                                $item.addClass('is-selected');
                            }
                            renderTrigger();
                            commit();
                        } else {
                            state.selected = o.value;
                            renderTrigger();
                            commit();
                            closePanel();
                        }
                    });
                    $panel.append($item);
                });
            }

            function commit() {
                if (widget.multiple) {
                    setFormPreviewValue(valueKey, state.selected.length ? state.selected.slice() : '');
                } else {
                    setFormPreviewValue(valueKey, state.selected === null ? '' : state.selected);
                }
            }

            function openPanel() {
                if (widget.disabled) return;
                state.open = true;
                renderPanel();
                $panel.removeAttr('hidden');
                setTimeout(function () {
                    $(document).one('click.fp_sel_' + valueKey, function (e) {
                        if (!$.contains($wrap[0], e.target)) closePanel();
                    });
                }, 0);
            }
            function closePanel() {
                state.open = false;
                $panel.attr('hidden', 'hidden');
                $(document).off('click.fp_sel_' + valueKey);
            }

            $trigger.on('click', function (e) {
                e.stopPropagation();
                if (state.open) closePanel();
                else openPanel();
            });

            $wrap.append($trigger).append($panel);
            $ctrl.append($wrap);

            renderTrigger();
        }

        function renderUploadCtrl($ctrl, widget, valueKey) {
            var $input = $('<input type="file" class="api-debug-form-preview__input" />');
            if (widget.disabled) $input.prop('disabled', true);
            $input.on('change', function () {
                if (widget.disabled) return;
                var f = this.files && this.files[0];
                setFormPreviewValue(valueKey, f ? f.name + ' (' + f.size + ' bytes)' : '');
            });
            $ctrl.append($input);
            $ctrl.append($('<p class="api-debug-form-preview__tip"></p>').text('（文件不实际上传，仅记录文件名）'));
        }

        // ------- antdv 风格 cascader:主 input + 多列 panel + hover/click 联动 -------
        // option 字段名读取策略:优先 label/value;否则按 backend 已 toLabelValue 转过,
        // 再 fallback id/department_name 类常见字段 → 最后 fallback options[0] 的 key
        function deriveCascaderLabel(opt) {
            if (!opt) return '';
            if (opt.label !== undefined) return String(opt.label);
            if (opt.name !== undefined) return String(opt.name);
            // backend 真实 sample 字段(部门、人员等)
            if (opt.department_name !== undefined) return String(opt.department_name);
            if (opt.real_name !== undefined) return String(opt.real_name);
            if (opt.title !== undefined) return String(opt.title);
            return JSON.stringify(opt).slice(0, 20);
        }
        function deriveCascaderValue(opt) {
            if (!opt) return '';
            if (opt.value !== undefined) return opt.value;
            if (opt.id !== undefined) return opt.id;
            if (opt.key !== undefined) return opt.key;
            return deriveCascaderLabel(opt);
        }
        function hasChildren(opt) {
            return opt && Array.isArray(opt.children) && opt.children.length > 0;
        }

        // 每个 cascader 实例都有自己的 state slot
        // multiple=false: state.path = [{opt, columnIdx}, ...] 一条路径
        // multiple=true:  state.paths = [[{opt,...}], [{opt,...}], ...] 多条路径
        function makeCascaderState(rootOptions, multiple) {
            return {
                open: false,
                multiple: !!multiple,
                roots: rootOptions || [],
                path: [],
                paths: [],
                // 多选展开时,当前正在选第几条路径(用于 panel column 显示 active 高亮)
                workingPath: []
            };
        }

        function renderCascader($ctrl, widget, valueKey) {
            var $wrap = $('<div class="api-debug-form-preview__cascader"></div>');
            if (widget.multiple) $wrap.addClass('is-multiple');
            var $trigger = $('<button type="button" class="api-debug-form-preview__cascader-trigger is-empty"></button>');
            $trigger.text(widget.placeholder || '请选择');
            if (widget.disabled) $trigger.prop('disabled', true);
            var $panel = $('<div class="api-debug-form-preview__cascader-panel" hidden></div>');

            var state = makeCascaderState(widget.options || [], widget.multiple);

            // 重渲染 panel(根据 state.workingPath 派生 columns)
            function repaintPanel() {
                $panel.empty();
                var activePath = state.multiple ? state.workingPath : state.path;
                appendColumn(state.roots, 0, activePath);
                activePath.forEach(function (step, idx) {
                    if (hasChildren(step.opt)) {
                        appendColumn(step.opt.children, idx + 1, activePath);
                    }
                });
            }

            function appendColumn(options, colIdx, activePath) {
                var $col = $('<div class="api-debug-form-preview__cascader-col"></div>');
                var activeAtThisLevel = activePath[colIdx] && activePath[colIdx].opt;
                options.forEach(function (opt) {
                    var $item = $('<div class="api-debug-form-preview__cascader-item"></div>');
                    $item.text(deriveCascaderLabel(opt));
                    if (hasChildren(opt)) $item.addClass('has-children');
                    if (activeAtThisLevel && deriveCascaderValue(activeAtThisLevel) === deriveCascaderValue(opt)) {
                        $item.addClass('is-active');
                    }
                    // 多选:标记已选路径末端。strictly 下父节点也可独立选中 → 同样参与标记
                    if (state.multiple && (widget.strictly || !hasChildren(opt))) {
                        var selVal = deriveCascaderValue(opt);
                        var selected = state.paths.some(function (p) {
                            var last = p[p.length - 1];
                            return last && deriveCascaderValue(last.opt) === selVal;
                        });
                        if (selected) $item.addClass('is-selected-leaf');
                    }
                    $item.on('click', function (e) {
                        e.stopPropagation();
                        onPickItem(opt, colIdx);
                    });
                    $col.append($item);
                });
                $panel.append($col);
            }

            function onPickItem(opt, colIdx) {
                if (widget.disabled) return;
                if (state.multiple) {
                    // 多选:用 workingPath 临时记录当前展开路径
                    state.workingPath = state.workingPath.slice(0, colIdx);
                    state.workingPath.push({ opt: opt, columnIdx: colIdx });
                    if (hasChildren(opt)) {
                        // strictly(= antd changeOnSelect):父节点也可独立 toggle 选中,同时展开下钻
                        if (widget.strictly) {
                            toggleMultipleSelection(state.workingPath.slice());
                            renderMultipleTrigger();
                        }
                        repaintPanel();
                    } else {
                        // 叶子:toggle add/remove
                        toggleMultipleSelection(state.workingPath.slice());
                        // 不关 panel,允许继续选
                        repaintPanel();
                        renderMultipleTrigger();
                    }
                } else {
                    // 单选(原逻辑)
                    state.path = state.path.slice(0, colIdx);
                    state.path.push({ opt: opt, columnIdx: colIdx });
                    if (hasChildren(opt)) {
                        // strictly:点父节点即写值,同时展开允许继续下钻(不关 panel)
                        if (widget.strictly) commitSelection();
                        repaintPanel();
                    } else {
                        commitSelection();
                        closePanel();
                    }
                }
            }

            function toggleMultipleSelection(pathSnapshot) {
                var leafVal = deriveCascaderValue(pathSnapshot[pathSnapshot.length - 1].opt);
                var existsIdx = state.paths.findIndex(function (p) {
                    var last = p[p.length - 1];
                    return last && deriveCascaderValue(last.opt) === leafVal;
                });
                if (existsIdx >= 0) {
                    state.paths.splice(existsIdx, 1);
                } else {
                    state.paths.push(pathSnapshot);
                }
                commitMultipleSelection();
            }

            function removeMultiplePathByLeaf(leafVal) {
                var existsIdx = state.paths.findIndex(function (p) {
                    var last = p[p.length - 1];
                    return last && deriveCascaderValue(last.opt) === leafVal;
                });
                if (existsIdx >= 0) state.paths.splice(existsIdx, 1);
                commitMultipleSelection();
                renderMultipleTrigger();
                if (state.open) repaintPanel();
            }

            function renderMultipleTrigger() {
                $trigger.empty();
                if (!state.paths.length) {
                    $trigger.addClass('is-empty').text(widget.placeholder || '请选择');
                    return;
                }
                $trigger.removeClass('is-empty');
                state.paths.forEach(function (p) {
                    var labels = p.map(function (s) { return deriveCascaderLabel(s.opt); });
                    var leafVal = deriveCascaderValue(p[p.length - 1].opt);
                    var $chip = $('<span class="api-debug-form-preview__cascader-chip"></span>')
                        .append($('<span class="api-debug-form-preview__cascader-chip-text"></span>').text(labels.join(' / ')));
                    var $del = $('<span class="api-debug-form-preview__cascader-chip-del" aria-label="移除"></span>').text('×');
                    $del.on('click', function (e) {
                        e.stopPropagation();
                        if (widget.disabled) return;
                        removeMultiplePathByLeaf(leafVal);
                    });
                    $chip.append($del);
                    $trigger.append($chip);
                });
            }

            function commitMultipleSelection() {
                if (!state.paths.length) {
                    setFormPreviewValue(valueKey, '');
                    return;
                }
                if (widget.array) {
                    setFormPreviewValue(valueKey, state.paths.map(function (p) {
                        return p.map(function (s) { return deriveCascaderValue(s.opt); });
                    }));
                } else {
                    setFormPreviewValue(valueKey, state.paths.map(function (p) {
                        return deriveCascaderValue(p[p.length - 1].opt);
                    }));
                }
            }

            function commitSelection() {
                if (!state.path.length) {
                    $trigger.addClass('is-empty').text(widget.placeholder || '请选择');
                    setFormPreviewValue(valueKey, '');
                    return;
                }
                var labels = state.path.map(function (s) { return deriveCascaderLabel(s.opt); });
                $trigger.removeClass('is-empty').text(labels.join(' / '));
                if (widget.array) {
                    setFormPreviewValue(valueKey, state.path.map(function (s) { return deriveCascaderValue(s.opt); }));
                } else {
                    var leaf = state.path[state.path.length - 1].opt;
                    setFormPreviewValue(valueKey, deriveCascaderValue(leaf));
                }
            }

            function openPanel() {
                if (widget.disabled) return;
                state.open = true;
                // 多选打开时:workingPath 从空开始,允许重新走树
                if (state.multiple) state.workingPath = [];
                repaintPanel();
                $panel.removeAttr('hidden');
                setTimeout(function () {
                    $(document).one('click.cascader_' + valueKey, function (e) {
                        if (!$.contains($wrap[0], e.target)) closePanel();
                    });
                }, 0);
            }
            function closePanel() {
                state.open = false;
                $panel.attr('hidden', 'hidden');
                $(document).off('click.cascader_' + valueKey);
            }

            $trigger.on('click', function (e) {
                e.stopPropagation();
                if (state.open) closePanel();
                else openPanel();
            });

            $wrap.append($trigger).append($panel);
            $ctrl.append($wrap);

            if (widget.strictly) {
                $ctrl.append($('<p class="api-debug-form-preview__tip"></p>').text('（strictly：可选中任意层级节点，父节点也写值）'));
            }

            // edit 场景:widget.default 有值时反向查 options 树 → 填 state 并显示路径(单选 / 多选分支)
            applyCascaderDefault();

            // 深度优先在嵌套 options 找单值 leaf path,返回 opt 数组(从 root 到叶)
            function findPathForLeaf(options, targetValue) {
                if (!Array.isArray(options)) return null;
                for (var i = 0; i < options.length; i++) {
                    var opt = options[i];
                    if (deriveCascaderValue(opt) == targetValue) return [opt]; // 容忍 == 字符串/数字
                    var sub = findPathForLeaf(opt.children, targetValue);
                    if (sub) return [opt].concat(sub);
                }
                return null;
            }

            function applyCascaderDefault() {
                if (widget.default === undefined || widget.default === null || widget.default === '') return;

                // ---- 多选 ----
                if (state.multiple) {
                    var rawList = Array.isArray(widget.default) ? widget.default : [widget.default];
                    var pathsFound = [];
                    var pathsMissing = [];
                    rawList.forEach(function (item) {
                        var opts = null;
                        if (Array.isArray(item)) {
                            // 路径数组
                            opts = [];
                            var cur = state.roots;
                            for (var i = 0; i < item.length; i++) {
                                var found = (cur || []).find(function (o) { return deriveCascaderValue(o) == item[i]; });
                                if (!found) { opts = null; break; }
                                opts.push(found);
                                cur = found.children || [];
                            }
                        } else {
                            opts = findPathForLeaf(state.roots, item);
                        }
                        if (opts && opts.length) {
                            pathsFound.push(opts.map(function (opt, idx) { return { opt: opt, columnIdx: idx }; }));
                        } else {
                            pathsMissing.push(item);
                        }
                    });
                    if (pathsFound.length) {
                        state.paths = pathsFound;
                        renderMultipleTrigger();
                    }
                    if (pathsMissing.length) {
                        // 在 chip list 后追加 ⚠ 提示
                        pathsMissing.forEach(function (v) {
                            var rawVal = Array.isArray(v) ? v.join(' / ') : String(v);
                            $trigger.removeClass('is-empty').append(
                                $('<span class="api-debug-form-preview__cascader-chip api-debug-form-preview__cascader-chip--missing"></span>').text('⚠ ' + rawVal)
                            );
                        });
                    }
                    return;
                }

                // ---- 单选 ----
                var pathOpts = null;
                if (Array.isArray(widget.default) && widget.default.length) {
                    pathOpts = [];
                    var cur = state.roots;
                    for (var j = 0; j < widget.default.length; j++) {
                        var v = widget.default[j];
                        var found = (cur || []).find(function (o) { return deriveCascaderValue(o) == v; });
                        if (!found) { pathOpts = null; break; }
                        pathOpts.push(found);
                        cur = found.children || [];
                    }
                } else if (!Array.isArray(widget.default)) {
                    pathOpts = findPathForLeaf(state.roots, widget.default);
                }
                if (pathOpts && pathOpts.length) {
                    state.path = pathOpts.map(function (opt, idx) { return { opt: opt, columnIdx: idx }; });
                    var labels = state.path.map(function (s) { return deriveCascaderLabel(s.opt); });
                    $trigger.removeClass('is-empty').text(labels.join(' / '));
                } else {
                    var rawVal = Array.isArray(widget.default) ? widget.default.join(' / ') : String(widget.default);
                    $trigger.removeClass('is-empty').text('⚠ ' + rawVal + ' （不在 options 内）');
                }
            }
        }


        // ------- 入口:接收响应 data → detect → render -------
        function tryRenderFormPreview(responseJson, overrideValues) {
            var $tab = $('#form_preview_tab');
            var $main = $('#form_preview_main');
            if (!$tab.length || !$main.length) return;

            // 3 种 shape 路径:
            //   (1) edit_get 类:{ data: {...初始值}, form_widgets: [[widget...], ...] }
            //   (2) create_get 类:{ data: [[widget...], ...] }(顶层 data 直接是 widget 二维)
            //   (3) 兜底:顶层就是 widget 数组
            var candidate = null;
            var initialValues = null;
            if (responseJson && typeof responseJson === 'object') {
                if (looksLikeFormWidgets(responseJson.form_widgets)) {
                    candidate = responseJson.form_widgets;
                    // edit 场景:data 是 { field: value, ... } 当初始 values
                    if (responseJson.data && typeof responseJson.data === 'object' && !Array.isArray(responseJson.data)) {
                        initialValues = responseJson.data;
                    }
                } else if (looksLikeFormWidgets(responseJson.data)) {
                    candidate = responseJson.data;
                } else if (looksLikeFormWidgets(responseJson)) {
                    candidate = responseJson;
                }
            }

            if (!candidate) {
                // 不命中:隐藏 tab,清空 state
                $tab.attr('hidden', 'hidden').removeClass('is-active');
                $main.empty();
                formPreviewState.matrix = [];
                formPreviewState.values = {};
                refreshFormPreviewOutput();
                // 若当前是 form-preview pane 激活,退回 body
                if ($('.api-debug-response-pane[data-pane="form-preview"]').hasClass('is-active')) {
                    $('.api-debug-response-tab[data-pane="body"]').trigger('click');
                }
                return;
            }

            // 命中:重置 state + 渲染
            $tab.removeAttr('hidden');
            formPreviewState.matrix = normalizeWidgetMatrix(candidate);
            // edit 场景:把 backend 返回的初始值作为 values 起点;overrideValues 是该 tab 切走前用户
            // 填好的预览值(切回时由 _restore 传入,优先于响应默认),避免切 tab 丢失表单预览输入(2026-06-09 修)。
            var baseValues = initialValues ? $.extend({}, initialValues) : {};
            if (overrideValues && typeof overrideValues === 'object') {
                baseValues = $.extend(baseValues, overrideValues);
            }
            formPreviewState.values = baseValues;
            $main.empty();

            var usedKeys = [];
            formPreviewState.matrix.forEach(function (row, rowIdx) {
                var $row = $('<div class="api-debug-form-preview__row"></div>');
                row.forEach(function (widget, colIdx) {
                    var valueKey = deriveValueKey(widget, rowIdx, colIdx, usedKeys);
                    // 若 values(初始值 + 用户 override)含该字段,inject 进 widget.default(让控件 render 时显示)
                    if (Object.prototype.hasOwnProperty.call(baseValues, valueKey)) {
                        widget = $.extend({}, widget, { default: baseValues[valueKey] });
                    }
                    $row.append(renderWidget(widget, valueKey));
                });
                $main.append($row);
            });

            refreshFormPreviewOutput();
        }

        // 暴露到全局,在 success 分支调用
        window.scaffoldFormPreviewTryRender = tryRenderFormPreview;
        // plan-50 tabs:tab switch 时把 stored response 重渲表单预览(沿用 tryRenderFormPreview);
        // 第二个参 overrideValues = 该 tab 切走前用户填的预览值(由 _restore 传入)。
        window.scaffoldRehydrateFormPreview = tryRenderFormPreview;
        // 供 ScaffoldDebugTabs._captureCurrent 在切 tab 前快照当前表单预览值(2026-06-09)
        window.scaffoldGetFormPreviewValues = function () {
            return $.extend({}, formPreviewState.values);
        };

        // ------- 响应摘要：耗时 / 体积 / 时间点 -------
        var formatBytes = function (n) {
            if (n < 1024) return n + " B";
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
            return (n / 1024 / 1024).toFixed(2) + " MB";
        };

        var formatElapsed = function (ms) {
            if (ms < 1000) return ms + " ms";
            return (ms / 1000).toFixed(2) + " s";
        };

        var formatClock = function (d) {
            var pad = function (n) { return n < 10 ? "0" + n : "" + n; };
            return pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds());
        };

        // ------- 错误块渲染（替代 inline-style 拼接） -------
        var renderErrorBlock = function (opts) {
            var $box = $('<div class="api-debug-error"></div>');
            if (opts.title) {
                $box.append($('<p class="api-debug-error__title"></p>').text(opts.title));
            }
            if (opts.items && opts.items.length) {
                var $list = $('<ul class="api-debug-error__list"></ul>');
                opts.items.forEach(function (item) {
                    var $li = $('<li class="api-debug-error__item' + (item.tone === 'muted' ? ' api-debug-error__item--muted' : '') + '"></li>');
                    if (item.label) {
                        $li.append($('<b></b>').text(item.label));
                        $li.append(document.createTextNode(': '));
                    }
                    $li.append(document.createTextNode(item.text || ''));
                    $list.append($li);
                });
                $box.append($list);
            }
            if (opts.raw !== undefined && opts.raw !== null) {
                var rawStr;
                try {
                    rawStr = typeof opts.raw === 'string' ? opts.raw : JSON.stringify(opts.raw, null, 2);
                } catch (e) {
                    rawStr = String(opts.raw);
                }
                var $details = $('<details class="api-debug-error__raw"></details>');
                $details.append($('<summary class="api-debug-error__raw-toggle"></summary>').text('查看原始响应'));
                $details.append($('<pre class="json-block"></pre>').text(rawStr));
                $box.append($details);
            }
            return $box;
        };

        // applyDom=false 时只计算不写 DOM(响应回来但已切走 tab:值仍要存进发起 tab 的 meta)
        var updateResultMeta = function (startMs, payload, applyDom) {
            var elapsed = formatElapsed(Date.now() - startMs);
            var size = "—";
            if (payload !== null && payload !== undefined) {
                try {
                    var raw = typeof payload === "string" ? payload : JSON.stringify(payload);
                    size = formatBytes(new Blob([raw]).size);   // byte length 按 UTF-8 估算
                } catch (e) { size = "—"; }
            }
            var time = formatClock(new Date());
            if (applyDom !== false) {
                $("#result_elapsed").text(elapsed);
                $("#result_size").text(size);
                $("#result_time").text(time);
                $("#result_meta").removeAttr("hidden");
            }
            return { elapsed: elapsed, size: size, time: time };
        };

        // ------- 发送按钮 -------
        $("body").on("click", "#send", function () {
            if ($(this).hasClass("disabled")) return;

            var $me = $(this),
                $body = $("#json_format"),
                sendingHost = $("#host").val() || "",
                sendingUri = $("#uri").val() || "",
                uri = sendingHost + sendingUri,
                method = $("#send_method").val(),
                $status = $("#result_status"),
                headers = validKey("#request_header"),
                urlParams = validKey("#request_params"),
                bodyParams = validKey("#request_body_params"),
                params = $.extend({}, urlParams, bodyParams),
                originalText = $me.text(),
                // 发起请求时的接口标识快照 —— 异步期间用户可能切到别的 tab(_syncSidebarHighlight
                // 会把 .is-active 移走),而 recordHistory 跑在响应回调里,必须用这份发送时的快照,
                // 否则最近记录会记成「切过去那个」接口的 f/c/a/host/uri(2026-06-09 修)。
                // (plan-27 后 sideTree 用 .side-tree__item.is-active > .side-tree__item-link)
                $sendingLink = $("#aside_container .side-tree__item.is-active .side-tree__item-link"),
                sendingMeta = {
                    f: $sendingLink.data("f"),
                    c: $sendingLink.data("c"),
                    a: $sendingLink.data("a"),
                    apiName: $sendingLink.data("api-name") || $.trim($sendingLink.text())
                },
                // 当前接口 action — 决定是否允许从响应体抓 token(只在认证类接口才抓,见 success)
                currentAction = String(sendingMeta.a || "");

            // 没 token 时别发空的 Authorization 头:Authorization 头复选框默认勾选,但 #auth_token 在
            // 无 host token 时被 restoreAuthToken 清成空 → validKey 收到 {Authorization:""} → 发出一个
            // 空头。部分鉴权中间件把"Authorization 存在但空/只有 Bearer"当畸形鉴权(400/401),跟"压根
            // 不带头"= guest 语义不同。空值时剔除,只针对标准 Authorization 头,自定义空头不动
            // (2026-06-10 修)。
            if (headers.Authorization !== undefined &&
                String(headers.Authorization).replace(/^Bearer\s*/i, "").trim() === "") {
                delete headers.Authorization;
            }

            // GET 的勾选参数由 proxy 拼进 query string —— 「实际地址」展示要带上,否则只显示 path、
            // 用户既看不到也复制不到真正发出去的完整 URL(2026-06-10 修)。_proxy_url 仍传 path-only
            // 的 uri,query 由后端 $http->get($url, $params) 追加,不能在这里重复拼(否则双 query)。
            var displayUri = uri;
            if (method === "GET" && ! $.isEmptyObject(params)) {
                displayUri = uri + (uri.indexOf("?") >= 0 ? "&" : "?") + $.param(params);
            }

            var recordHistory = function (status) {
                if (typeof window.recordApiHistoryEntry !== "function") return;
                window.recordApiHistoryEntry({
                    method: method,
                    host: sendingHost,
                    uri: sendingUri,
                    full_url: displayUri,
                    status: status,
                    elapsed_ms: Date.now() - requestStartMs,
                    folder: sendingMeta.f,
                    controller: sendingMeta.c,
                    action: sendingMeta.a,
                    api_name: sendingMeta.apiName,
                    headers: headers,
                    url_params: urlParams,
                    body_params: bodyParams
                });
            };

            $me.addClass("disabled").text("发送中");
            $body.html('<p class="requesting">Sending...</p>');
            $("#header").html("等待响应头……");
            $("#result_method").html(method);
            $("#result_uri").html(displayUri);
            $status.html("WAIT").attr("class", "status");
            $("#result_meta").attr("hidden", "hidden");

            var requestStartMs = Date.now();
            // 发起请求的 tab id —— 响应回来时按它回写,避免异步期间切 tab 把响应记到别的 tab(2026-06-09 修)
            var sendingTabId = window.ScaffoldDebugTabs ? ScaffoldDebugTabs.activeTabId : null;

            cacheParams($("#uri").val());

            $.ajax({
                url: SC.apiProxy,
                type: "POST",
                dataType: "json",
                data: {
                    _proxy_url: uri,
                    _proxy_method: method,
                    _proxy_headers: headers,
                    _proxy_params: params
                },
                success: function (json) {
                    var realStatus = json._proxy_status || 0;
                    var proxyHeaders = json._proxy_headers || {};
                    delete json._proxy_status;
                    delete json._proxy_headers;

                    // 是否仍停在发起请求的 tab?切走了就不动 live DOM(响应只存进发起 tab,切回时
                    // _restore 还原),避免异步响应污染当前别的 tab(2026-06-09 修)。
                    var stillActive = ! window.ScaffoldDebugTabs || ScaffoldDebugTabs.activeTabId === sendingTabId;
                    var rm = updateResultMeta(requestStartMs, json, stillActive);

                    // 统一提取 token:
                    //  - 响应头带 authorization → 直接用(真实的 token 刷新,任何接口都认);
                    //  - 否则仅在「认证类」接口(登录/注册/refresh)才从响应体抓 token —— 否则
                    //    普通资源响应里恰好有 token / access_token 字段会被误当登录态,覆盖掉真实
                    //    Bearer → 下次请求 401(2026-06-09 修)。
                    var newToken = "";
                    if (proxyHeaders["authorization"]) {
                        newToken = proxyHeaders["authorization"].replace(/^Bearer\s+/i, "");
                    } else if (/login|logon|authenticate|register|refresh.?token/i.test(currentAction)) {
                        var src = json.data || json;
                        var raw = (src && (src.token || src.access_token)) || json.token || json.access_token || "";
                        if (raw) newToken = String(raw).replace(/^Bearer\s+/i, "");
                    }

                    // 「响应头」面板始终展示真实响应头 —— 原先一旦抓到 token 就把整个面板替换成
                    // "Authorization: Bearer xxx"(请求 token),登录响应的真实响应头(Set-Cookie /
                    // Content-Type 等)全看不到了;而 token 已经回填进 #auth_token 字段 + 绿色状态,
                    // 在响应头里再塞一遍既冗余又遮挡真头(2026-06-10 修)。
                    var headerStr = "";
                    for (var k in proxyHeaders) headerStr += k + ": " + proxyHeaders[k] + "\n";
                    if (newToken) {
                        // 持久化到「发起请求时」的 host —— 不是 $("#host").val()(异步期间用户可能已切
                        // host 下拉,会把本次登录拿到的 token 错存到另一个 host 名下,下次该 host 请求
                        // 用错/空 token → 莫名 401。沿用 #send 的 sendingTabId / sendingMeta capture 套路。
                        saveHostToken(sendingHost, newToken);
                    }

                    $me.removeClass("disabled").text(originalText);        // 发送按钮全局,总是恢复

                    if (stillActive) {
                        if (newToken) $("#auth_token").val("Bearer " + newToken);
                        $("#header").text(headerStr);
                        $status.html(realStatus);
                        if (realStatus >= 200 && realStatus < 300) {
                            $status.attr("class", "status font-green");
                            Process({ id: "json_format", data: json });
                        } else if (realStatus == 422 && json.errors) {
                            $status.attr("class", "status font-orange");
                            var validationItems = [];
                            for (var f in json.errors) {
                                // 归一成数组:某些 API 把单条错误返回成字符串,否则 fMsgs[j] 会逐字符拆开(2026-06-09 修)
                                var fMsgs = [].concat(json.errors[f]);
                                for (var j = 0; j < fMsgs.length; j++) {
                                    validationItems.push({ label: f, text: fMsgs[j] });
                                }
                            }
                            $body.empty().append(renderErrorBlock({
                                title: json.message || "Validation Error",
                                items: validationItems,
                                raw: json
                            }));
                        } else {
                            $status.attr("class", "status font-red");
                            var titles = { 401: "401 未授权", 403: "403 禁止访问", 404: "404 接口不存在", 500: "500 服务器错误" };
                            var title = titles[realStatus] || (realStatus + " 请求失败");
                            var errorItems = [{ text: json.message || ("HTTP Error " + realStatus) }];
                            if (json.exception) {
                                errorItems.push({ label: "Exception", text: json.exception, tone: "muted" });
                            }
                            $body.empty().append(renderErrorBlock({
                                title: title,
                                items: errorItems,
                                raw: json
                            }));
                        }

                        // form_widgets detect + render — 不论 status,都跑一次:
                        // 命中(顶层或 data 是 widget shape)→ 亮 tab + 渲染
                        // 不命中 → 清空旧 state + 隐藏 tab(防 500 残留上次 200 的旧表单)
                        if (typeof window.scaffoldFormPreviewTryRender === 'function') {
                            window.scaffoldFormPreviewTryRender(json);
                        }
                    }

                    // 回写到「发起请求的」tab(按 id,始终),切回时 _restore 还原
                    if (window.ScaffoldDebugTabs) {
                        var meta = {
                            status: realStatus, method: method, uri: displayUri,
                            elapsed: rm.elapsed, size: rm.size, time: rm.time,
                        };
                        ScaffoldDebugTabs.notifySendSuccess(json, meta, sendingTabId);
                        ScaffoldDebugTabs.notifySendHeaders(headerStr, sendingTabId);
                    }

                    recordHistory(realStatus);
                },
                error: function (xhr) {
                    $me.removeClass("disabled").text(originalText);
                    var stillActive = ! window.ScaffoldDebugTabs || ScaffoldDebugTabs.activeTabId === sendingTabId;
                    updateResultMeta(requestStartMs, null, stillActive);
                    if (stillActive) {
                        // 这里是 scaffold 代理层自身的错(没出网),不是上游 API 的错 —— 原先一律
                        // 显示"请检查网络连接",419(会话过期)/429(throttle:60,1 批量调试真会命中)/
                        // 422(代理参数校验)全被掩盖,用户无从排查(2026-06-10 修)。
                        var st = (xhr && xhr.status) || 0;
                        var resp = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                        var msg;
                        if (st === 419) {
                            msg = "scaffold 会话过期（CSRF token 失效），请刷新页面后重试";
                        } else if (st === 429) {
                            msg = "请求过于频繁，触发 scaffold 代理限流（60 次/分钟），稍候再发";
                        } else if (resp && resp.message) {
                            msg = resp.message;
                        } else {
                            msg = "代理请求异常，请检查网络连接";
                        }
                        var items = [{ text: msg }];
                        if (st) items.push({ label: "代理层状态码", text: String(st), tone: "muted" });
                        $status.html(st ? String(st) : "ERR").attr("class", "status font-red");
                        $body.empty().append(renderErrorBlock({
                            title: "请求失败（scaffold 代理层，未到达目标接口）",
                            items: items,
                            raw: xhr && xhr.responseText ? xhr.responseText : null
                        }));
                    }
                    recordHistory((xhr && xhr.status) || 0);
                }
            });
        });

        // ------- Clipboard 复制 -------
        if (typeof ClipboardJS !== "undefined") {
            var showEm = function (e, type) {
                var color = type === "success" ? "green" : "red";
                var $h3 = $(e.trigger).parents(".panel").find(".hd h3");
                var $em = $('<em class="font-' + color + '">copy ' + type + "</em>");
                $h3.append($em);
                $em.animate({ opacity: 1 }, 1500, function () { $(this).remove(); });
                e.trigger.focus();
            };

            var clipboard = new ClipboardJS(".table .key", {
                // .key 只读列已从 <input> 改为 <span>（移出 Tab 序）：span 无 .val()，读 .text()；
                // 动态新增行里 .key 仍是 <input>，走 .val()。两者都兼容点击复制。
                text: function (e) {
                    var $e = $(e);
                    return $e.is("input, textarea, select") ? $e.val() : $e.text();
                }
            });
            clipboard.on("success", function (e) { showEm(e, "success"); });
            clipboard.on("error", function (e) { showEm(e, "error"); });

            // 响应区复制按钮:复制当前激活 pane 的纯文本(Body / Headers)
            var responseClipboard = new ClipboardJS("#response_copy_btn", {
                text: function () {
                    var $pane = $(".api-debug-response-pane.is-active").find(".json-block").first();
                    if (!$pane.length) $pane = $(".api-debug-response-pane.is-active").first();
                    return ($pane.text() || "").trim();
                }
            });

            // 2026-05-23 表单预览复制按钮:复制 #form_preview_output 的当前表单值 JSON
            var formPreviewClipboard = new ClipboardJS("#form_preview_copy_btn", {
                text: function () { return ($("#form_preview_output").text() || "").trim(); }
            });
            formPreviewClipboard.on("success", function (e) {
                var $btn = $(e.trigger);
                var original = $btn.data("orig") || $btn.text();
                $btn.data("orig", original).text("已复制").addClass("is-copied");
                setTimeout(function () { $btn.text(original).removeClass("is-copied"); }, 1200);
                if (e.clearSelection) e.clearSelection();
            });
            formPreviewClipboard.on("error", function (e) {
                var $btn = $(e.trigger);
                var original = $btn.data("orig") || $btn.text();
                $btn.data("orig", original).text("复制失败");
                setTimeout(function () { $btn.text(original); }, 1500);
            });
            responseClipboard.on("success", function (e) {
                var $btn = $(e.trigger);
                var original = $btn.data("orig") || $btn.text();
                $btn.data("orig", original).text("已复制").addClass("is-copied");
                setTimeout(function () { $btn.text(original).removeClass("is-copied"); }, 1200);
                if (e.clearSelection) e.clearSelection();
            });
            responseClipboard.on("error", function (e) {
                var $btn = $(e.trigger);
                var original = $btn.data("orig") || $btn.text();
                $btn.data("orig", original).text("复制失败");
                setTimeout(function () { $btn.text(original); }, 1500);
            });
        }

        // ------- 表格 ↔ JSON 编辑视图切换 -------
        // 2026-05-23:旧实现是只读 HTML 预览;改为 textarea 可编辑,支持外部粘贴 JSON 回填表格。
        // 切到 JSON 视图时把当前表格状态 stringify 进 textarea;点"应用 JSON"把 textarea 解析回表格。
        var fillJsonEditor = function (id) {
            var $panel = $("#" + id).parents(".panel");
            var $tex = $panel.find(".api-debug-json-editor");
            $panel.find(".api-debug-json-status").text("").removeClass("is-err is-ok");
            var option = validKey("#" + id);
            var str = JSON.stringify(option, null, 2);
            $tex.val(str === "{}" ? "" : str);
        };

        var serializeJsonValueToInput = function (v) {
            // 标量直进 input value;数组/对象 stringify(validKey 收回时 parseParamValue 会再 JSON.parse)
            if (v === null || v === undefined) return "";
            if (typeof v === "boolean") return v ? "true" : "false";
            if (typeof v === "number") return String(v);
            if (typeof v === "string") return v;
            try { return JSON.stringify(v); } catch (e) { return String(v); }
        };

        var applyJsonToRows = function ($panel) {
            var $tex = $panel.find(".api-debug-json-editor");
            var $status = $panel.find(".api-debug-json-status");
            var raw = $.trim($tex.val());
            $status.removeClass("is-err is-ok");
            if (!raw) { $status.addClass("is-err").text("JSON 为空"); return false; }
            var data;
            try { data = JSON.parse(raw); }
            catch (e) { $status.addClass("is-err").text("JSON 解析失败：" + e.message); return false; }
            if (!data || typeof data !== "object" || $.isArray(data)) {
                $status.addClass("is-err").text("根必须是 object，如 { \"key\": ... }");
                return false;
            }

            // 找该 panel 下的 .table 容器(单个 panel 只有一个)
            var $tbl = $panel.find(".table.debug-param-table");
            if (!$tbl.length) { $status.addClass("is-err").text("找不到参数表"); return false; }

            // 行驱动 + 按路径解析:对每个参数行,用它的 key(cache-key / send-key / .key)从粘贴的
            // (可能嵌套的)JSON 里下钻取值。原来是「数据驱动 + 平铺 key 直配」,bracket-key 参数
            // (items[0][name])在 validKey 里被存成嵌套 {items:[{name:..}]},数据驱动只看顶层 key
            // 'items' → 配不到任何行 → 这类参数永远应用不上(2026-06-10 修)。
            var resolve = window.scaffoldResolvePathValue || function (o, k) {
                return k && Object.prototype.hasOwnProperty.call(o, k) ? { found: true, value: o[k] } : { found: false };
            };
            var matched = 0, consumedTop = {};
            $tbl.find("tr").each(function () {
                var $row = $(this);
                var keys = [
                    $row.find(".cache-key").val(),
                    $row.find(".send-key").val(),
                    $row.find("input.key").val()
                ];
                for (var i = 0; i < keys.length; i++) {
                    if (!keys[i]) continue;
                    var r = resolve(data, keys[i]);
                    if (!r.found) continue;
                    var $cb = $row.find(".checkbox");
                    if ($cb.length && !$cb.prop("disabled")) $cb.prop("checked", true);
                    var $val = $row.find(".value");
                    if ($val.length) $val.val(serializeJsonValueToInput(r.value));
                    matched++;
                    // 标记被消费的顶层 key(items[0][name] → items),供 unmatched 统计
                    var top = (keys[i].match(/^[^[\].]+/) || [keys[i]])[0];
                    consumedTop[top] = true;
                    break;
                }
            });

            var unmatched = Object.keys(data).filter(function (k) { return !consumedTop[k]; });
            var msg = "已应用 " + matched + " 项";
            if (unmatched.length) msg += "（忽略 " + unmatched.length + " 个未匹配 key：" + unmatched.slice(0, 5).join(", ") + (unmatched.length > 5 ? "……" : "") + "）";
            $status.addClass(unmatched.length ? "is-err" : "is-ok").text(msg);
            return true;
        };

        $("body").on("click", ".api-debug-toggle", function () {
            var $el = $(this).parents(".panel").find(".api-debug-tab-bd.active");
            $el.removeClass("active").siblings().addClass("active");

            if ($(this).attr("_on") == 1) {
                $(this).attr("_on", 0);
            } else {
                var id = $el.find(".table").attr("id");
                $(this).attr("_on", 1);
                if (id) fillJsonEditor(id);
            }
        });

        // 2026-05-23 user 反馈:去掉手动"应用 JSON"按钮,textarea input/paste 自动回填(debounce 200ms)
        // - paste 几乎瞬时(浏览器 paste 走 input event,debounce 后一次性 apply)
        // - 手打 / 编辑也 debounce apply,parse 失败时只更状态(不弹错),不打断输入
        // 去抖定时器按编辑器各自存(原来共用一个全局变量 → 200ms 内编辑两个面板的 JSON,
        // 前一个的 applyJsonToRows 会被后一个 clearTimeout 掉、那段 JSON 没回填,2026-06-09 修)
        $("body").on("input", ".api-debug-json-editor", function () {
            var $editor = $(this);
            var $panel = $editor.parents(".panel");
            clearTimeout($editor.data("jsonApplyTimer"));
            $editor.data("jsonApplyTimer", setTimeout(function () { applyJsonToRows($panel); }, 200));
        });

        // 2026-05-23 user 反馈:粘贴按钮 — feature-detect:只在 secure context(HTTPS/localhost)
        // 显出来。HTTP 站点 navigator.clipboard.readText 浏览器规范上不开放,user 直接
        // textarea + Cmd/Ctrl+V 一样能粘(已经 work)。按钮藏掉免出现"不支持" 错误提示。
        if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.readText === "function") {
            $("body").addClass("has-clipboard-read");
        }
        $("body").on("click", ".api-debug-json-paste", async function () {
            // 点按钮前先切到 JSON 视图(如果在表格视图)
            var $panel = $(this).parents(".panel");
            var $toggle = $panel.find(".api-debug-toggle");
            if ($toggle.attr("_on") !== "1") $toggle.trigger("click");
            var $tex = $panel.find(".api-debug-json-editor");
            var $status = $panel.find(".api-debug-json-status");
            $status.removeClass("is-ok is-err").text("");
            try {
                var text = await navigator.clipboard.readText();
                if (!text || !text.trim()) {
                    $status.addClass("is-err").text("剪贴板为空");
                    return;
                }
                $tex.val(text);
                $tex.trigger("input");
                $tex.focus();
            } catch (e) {
                $status.addClass("is-err").text("剪贴板权限被拒，请手动 Cmd/Ctrl+V");
                $tex.focus();
            }
        });

        // ------- Input title tooltip -------
        $("body").on("mouseover", ".table .txt", function () {
            $(this).attr("title", $(this).val());
        });
    });
})(jQuery);
