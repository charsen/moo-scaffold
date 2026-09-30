/* API 调试历史脱敏：原请求只用于发送，持久化/回填使用脱敏副本。 */
(function () {
    'use strict';
    var MASK = '***';
    var SECRET = /^(password|passwd|pwd|old_password|new_password|token|secret|api_?key|access_token|refresh_token|authorization)$/i;
    var HEADERS = ['authorization', 'cookie', 'x-csrf-token'];

    function sensitive(key) {
        return String(key).split(/[\[\].]+/).some(function (part) { return SECRET.test(part); });
    }

    function maskParams(value) {
        if (!value || typeof value !== 'object') return value;
        var out = Array.isArray(value) ? [] : {};
        Object.keys(value).forEach(function (key) {
            Object.defineProperty(out, key, {
                value: sensitive(key) ? MASK : maskParams(value[key]),
                enumerable: true, writable: true, configurable: true
            });
        });
        return out;
    }

    function maskUrl(value) {
        if (typeof value !== 'string') return value;
        var fragment = value.indexOf('#');
        if (fragment >= 0) value = value.slice(0, fragment);
        var question = value.indexOf('?');
        if (question < 0) return value;
        var query = value.slice(question + 1).split('&').map(function (pair) {
            var key = pair.split('=')[0], decoded;
            try { decoded = decodeURIComponent(key.replace(/\+/g, ' ')); } catch (e) { decoded = key; }
            return sensitive(decoded) ? key + '=' + encodeURIComponent(MASK) : pair;
        }).join('&');
        return value.slice(0, question + 1) + query;
    }

    function sanitizeEntry(entry) {
        var out = Object.assign({}, entry);
        out.headers = Object.assign({}, entry.headers);
        Object.keys(out.headers).forEach(function (key) {
            if (HEADERS.indexOf(key.toLowerCase()) >= 0) out.headers[key] = MASK;
        });
        out.url_params = maskParams(entry.url_params);
        out.body_params = maskParams(entry.body_params);
        ['full_url', 'uri', 'host'].forEach(function (key) {
            if (key in out) out[key] = maskUrl(out[key]);
        });
        return out;
    }

    window.ScaffoldDebugHistory = { sanitizeEntry: sanitizeEntry };
})();
