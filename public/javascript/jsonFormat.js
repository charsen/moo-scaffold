(function() {
    window.TAB = "    ";
    window.ImgCollapsed = "Collapsed.gif";
    window.ImgExpanded = "Expanded.gif";
    function $id(id) {
        return document.getElementById(id);
    }
    function IsArray(obj) {
        return (
            obj &&
            typeof obj === "object" &&
            typeof obj.length === "number" &&
            !obj.propertyIsEnumerable("length")
        );
    }

    // 响应体由 scaffold 代理透传上游 API,完全攻击者可控 —— 任何拼进 innerHTML 的字段
    // 必须先转义。字符串「值」原本在 FormatLiteral 里转了 <>,但对象「键名」(prop)此前
    // 直接拼接,上游返 {"<img src=x onerror=...>":1} 即可在开发者已登录的面板里执行脚本
    // (2026-06-10 修)。
    function escapeHtmlText(s) {
        return String(s)
            .split("&").join("&amp;")
            .split("<").join("&lt;")
            .split(">").join("&gt;");
    }

    function Process(option) {
        var json = JSON.stringify(option.data);
        var html = "";
        try {
            if (json == "") json = '""';
            // 不能用 eval(CSP 禁 unsafe-eval);JSON.stringify 的结果直接 JSON.parse 等价
            var obj = JSON.parse("[" + json + "]");
            html = ProcessObject(obj[0], 0, false, false, false);
            $id(option.id).innerHTML =
                "<PRE class='CodeContainer'>" + html + "</PRE>";
        } catch (e) {
            $id(option.id).innerHTML = "JSON数据格式不正确：\n" + e.message;
        }
    }

    window._dateObj = new Date();
    window._regexpObj = new RegExp();

    function ProcessObject(obj, indent, addComma, isArray, isPropertyContent) {
        var html = "";
        var comma = addComma ? "<span class='Comma'>,</span> " : "";
        var type = typeof obj;
        var clpsHtml = "";
        if (IsArray(obj)) {
            if (obj.length == 0) {
                html += GetRow(
                    indent,
                    "<span class='ArrayBrace'>[ ]</span>" + comma,
                    isPropertyContent
                );
            } else {
                clpsHtml =
                    '<span><span class="Expanded"></span></span><span class=\'collapsible\'>';
                html += GetRow(
                    indent,
                    "<span class='ArrayBrace'>[</span>" + clpsHtml,
                    isPropertyContent
                );
                for (var i = 0; i < obj.length; i++) {
                    html += ProcessObject(
                        obj[i],
                        indent + 1,
                        i < obj.length - 1,
                        true,
                        false
                    );
                }
                clpsHtml = "</span>";
                html += GetRow(
                    indent,
                    clpsHtml + "<span class='ArrayBrace'>]</span>" + comma
                );
            }
        } else if (type == "object") {
            if (obj == null) {
                html += FormatLiteral(
                    "null",
                    "",
                    comma,
                    indent,
                    isArray,
                    "Null"
                );
            } else if (obj.constructor == window._dateObj.constructor) {
                html += FormatLiteral(
                    "new Date(" +
                        obj.getTime() +
                        ") /*" +
                        obj.toLocaleString() +
                        "*/",
                    "",
                    comma,
                    indent,
                    isArray,
                    "Date"
                );
            } else if (obj.constructor == window._regexpObj.constructor) {
                html += FormatLiteral(
                    "new RegExp(" + obj + ")",
                    "",
                    comma,
                    indent,
                    isArray,
                    "RegExp"
                );
            } else {
                var numProps = 0;
                for (var prop in obj) numProps++;
                if (numProps == 0) {
                    html += GetRow(
                        indent,
                        "<span class='ObjectBrace'>{ }</span>" + comma,
                        isPropertyContent
                    );
                } else {
                    clpsHtml =
                        '<span><span class="Expanded"></span></span><span class=\'collapsible\'>';
                    html += GetRow(
                        indent,
                        "<span class='ObjectBrace'>{</span>" + clpsHtml,
                        isPropertyContent
                    );

                    var j = 0;

                    for (var prop in obj) {
                        html += GetRow(
                            indent + 1,
                            "<span class='PropertyName'>" +
                                escapeHtmlText(prop) +
                                "</span>: " +
                                ProcessObject(
                                    obj[prop],
                                    indent + 1,
                                    ++j < numProps,
                                    false,
                                    true
                                )
                        );
                    }

                    clpsHtml = "</span>";

                    html += GetRow(
                        indent,
                        clpsHtml + "<span class='ObjectBrace'>}</span>" + comma
                    );
                }
            }
        } else if (type == "number") {
            html += FormatLiteral(obj, "", comma, indent, isArray, "Number");
        } else if (type == "boolean") {
            html += FormatLiteral(obj, "", comma, indent, isArray, "Boolean");
        } else if (type == "function") {
            if (obj.constructor == window._regexpObj.constructor) {
                html += FormatLiteral(
                    "new RegExp(" + obj + ")",
                    "",
                    comma,
                    indent,
                    isArray,
                    "RegExp"
                );
            } else {
                obj = FormatFunction(indent, obj);

                html += FormatLiteral(
                    obj,
                    "",
                    comma,
                    indent,
                    isArray,
                    "Function"
                );
            }
        } else if (type == "undefined") {
            html += FormatLiteral(
                "undefined",
                "",
                comma,
                indent,
                isArray,
                "Null"
            );
        } else {
            html += FormatLiteral(
                obj
                    .toString()
                    .split("\\")
                    .join("\\\\")
                    .split('"')
                    .join('\\"'),
                '"',
                comma,
                indent,
                isArray,
                "String"
            );
        }

        return html;
    }

    function FormatLiteral(literal, quote, comma, indent, isArray, style) {
        if (typeof literal == "string")
            literal = escapeHtmlText(literal);

        var str =
            "<span class='" +
            style +
            "'>" +
            quote +
            literal +
            quote +
            comma +
            "</span>";

        if (isArray) str = GetRow(indent, str);

        return str;
    }

    function FormatFunction(indent, obj) {
        var tabs = "";

        for (var i = 0; i < indent; i++) tabs += window.TAB;

        var funcStrArray = obj.toString().split("\n");

        var str = "";

        for (var i = 0; i < funcStrArray.length; i++) {
            str += (i == 0 ? "" : tabs) + funcStrArray[i] + "\n";
        }

        return str;
    }

    function GetRow(indent, data, isPropertyContent) {
        var tabs = "";

        for (var i = 0; i < indent && !isPropertyContent; i++)
            tabs += window.TAB;

        if (
            data != null &&
            data.length > 0 &&
            data.charAt(data.length - 1) != "\n"
        )
            data = data + "\n";

        return tabs + data;
    }

    function ExpClicked(el) {
        var container = el.parentNode.nextSibling;

        if (!container) return;

        var disp = "none";

        var className = "Collapsed",
            str = "...";

        if (container.style.display == "none") {
            disp = "inline";
            className = "Expanded";
            str = "";
        }

        container.style.display = disp;
        el.className = className;
        el.innerHTML = str;
    }

    window.Process = Process;
    window.ExpClicked = ExpClicked;

    // CSP 不允许 inline onclick,改用 document 级事件委托
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t && (t.className === 'Expanded' || t.className === 'Collapsed')) {
            ExpClicked(t);
        }
    });
})();
