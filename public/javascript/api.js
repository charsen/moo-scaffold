/**
 * /scaffold 的**统一响应解包层**（统一 JSON 信封 · 第 2 项）。
 *
 * 为什么需要它：收敛前后端有 ~10 种响应形态，前端 55 处错误提取写成**两套互不兼容**的读法 ——
 * `designer.js` 把 `json.error` 当**对象**（取 `.msg` / `.code`），`docs-*.js` 当**字符串**（直接 toast）。
 * 同一个后端契约、两种解析方式，任何一次后端改动都要在两边各改一遍、还容易漏。
 *
 * 本层把「怎么判定成败 / 怎么取业务数据 / 怎么取错误文案」收敛成一处，全站复用。
 *
 * 输入形态只有两种，别自造第三种：
 *   - jQuery `jqXHR` —— `pick()` 自动读 `.responseJSON` / `.responseText`
 *   - **fetch 的 `fromFetch(res, json)`** —— `res.json()` 会**消费掉 body**，
 *     所以必须显式把「HTTP 状态 + 已解析体」一起打包；直接传 `res` 会丢掉服务端文案。
 *
 * **迁移期双形态兼容**：后端正逐控制器迁移，新旧响应在一段时间内并存，所以本层两种都吃
 * （见 `isOk()` / `data()`）。这让迁移可以**按控制器灰度进行**，而不必一次性改完前端。
 *
 * 后端形态对照：
 *   新信封：{ok:true, data:{…}}                    成功
 *           {ok:false, error:{code,msg,detail}} + HTTP 失败
 *   旧形态：裸数据 {q,results,…} / {ok:true,…payload}   ← 无 error 键即视为成功
 *           {error:"字符串"}（docs / 中间件）/ {message:"…"}（异常出口）
 *           {_proxy_status:N, message}（API 代理：HTTP 恒 200，真实状态在 body 里）
 *
 * ⚠ `ApiProxyController` 的响应**不走信封**（上游 body 必须原样透传），本层对它单独分支，
 *   别按新信封解读它的 body。
 */
(function (window) {
    'use strict';

    /**
     * 把 XHR / fetch Response / 已解析的 json 统一成「已解析的 json」。
     * 拿不到就返回 null（调用方一律当失败处理）。
     */
    function pick(src) {
        if (!src || typeof src !== 'object') {
            return null;
        }
        // XHR（jQuery jqXHR / 原生 XMLHttpRequest）
        if ('responseJSON' in src || 'responseText' in src) {
            if (src.responseJSON) {
                return src.responseJSON;
            }
            try {
                return JSON.parse(src.responseText || 'null');
            } catch (e) {
                return null;
            }
        }
        // fetch 的 Response 是**异步**的（.json() 返回 Promise），本层只处理已解析体：
        // 调用方请先 await r.json()，再把结果传进来。
        return src;
    }

    /** HTTP 状态码；拿不到返回 0。 */
    function httpStatus(src) {
        if (src && typeof src.status === 'number') {
            return src.status; // fetch Response / jqXHR 都有 .status
        }
        return 0;
    }

    /** 判定成败。HTTP 状态码优先，再看 body 里的标记。 */
    function isOk(src) {
        // HTTP 状态优先，而且对「失败」而言它**已经足够**：
        // `{message:"…"}`（异常出口）body 里没有任何成败标记，只有状态码能判。
        // 反过来，2xx 上的 `error` 键**不一定**是失败 —— 见下面的领域字段分支。
        var status = httpStatus(src);
        if (status >= 400) {
            return false;
        }

        var j = pick(src);
        if (!j || typeof j !== 'object') {
            return false;
        }
        if (typeof j.ok === 'boolean') {
            return j.ok; // 新信封（也覆盖 DesignerController 一直以来的形态）
        }
        if (j._proxy_status) {
            // 代理：真实状态藏在 body 里，HTTP 恒 200
            return j._proxy_status >= 200 && j._proxy_status < 300;
        }

        // ⚠ `error` 键**只在拿不到状态码时**才当失败信号。
        //
        // 因为 2xx 上的 `error` 可能是**领域字段**而不是失败：本地 Markdown 预览
        // （`PlansController::preview` / `ReleaseRecordsController::preview`，
        // src/Http/Controllers/PlansController.php:77）返回的是
        //     {html: '<已渲染正文>', error: <frontmatter 警告 | null>}
        // + HTTP 200 —— 预览**成功了**，正文也渲染了，只是 frontmatter 有问题、
        // 需要在编辑器里就地提示。若在这里判失败，一次成功的预览会被前端当成请求失败。
        //
        // 旧形态里真正带 `error` 的失败（三个 Enforce* 中间件、DocsController）
        // 一律配 4xx/5xx，上面那句状态码判断已经拦住了，不依赖这条启发式。
        if (status === 0 && j.error) {
            return false;
        }
        return true; // 裸数据 / {ok:true,…payload} / {status:'ok'} / 2xx+领域 error 字段
    }

    /**
     * 取业务数据。
     *
     * 返回值必须与迁移前的旧写法**逐形状等价** —— 旧代码是
     * `return (json && json.data) ? json.data : json;`，即**真值判断**。
     * 所以这里对旧形态刻意保留真值判断、而不是写成 `j.data !== undefined`：
     * 后者会让 `{data:0}` / `{data:null}` / `{data:''}` 从「返回整包」变成「返回那个假值」，
     * 是旧代码下**不会发生**的形状变化（代理响应 `{data:<上游 body>, _proxy_status:N}` 会踩到）。
     *
     * 唯一**有意**的差异只在**新信封**上（旧代码从没见过 `ok` 键）：信封里出现 `data` 键就是
     * 「这就是载荷」，哪怕载荷本身是 `null` / `0` / `''` / `false` 也照样取出来 —— 否则
     * `{ok:true, data:null}` 会被当成"没载荷"而把整个信封漏给调用方。
     */
    function data(src) {
        var j = pick(src);
        if (!j || typeof j !== 'object') {
            return j;
        }
        if (typeof j.ok === 'boolean' && 'data' in j) {
            return j.data; // 新信封
        }
        return j.data ? j.data : j; // 旧形态：忠实复刻 `json.data ? json.data : json`
    }

    /**
     * 取**人类可读**错误文案（直接进 toast）。
     *
     * 取值优先级（越靠前越"具体"）：
     *   1. body 里的服务端文案 —— 最权威
     *   2. `fallback` —— 调用方给的**语境**文案（"保存失败"比"HTTP 502"更能告诉用户发生了什么）
     *   3. `HTTP <状态码>` —— 只知道"传输层失败了"，信息量最低
     *   4. `请求失败` —— 最后的兜底
     *
     * 为什么 `fallback` 要压过 HTTP 状态码：空 body + 502 时（上游挂了、网关截断），
     * 状态码说不出**用户当时在做什么**，而调用方知道。想两者都要的话，
     * 调用方自己传 `'保存失败（HTTP 502）'` 即可 —— 决定权留在调用方。
     */
    function errorText(src, fallback) {
        var j = pick(src);
        if (j && typeof j === 'object') {
            if (typeof j.error === 'string' && j.error) {
                return j.error; // 旧：DocsController / 三个中间件
            }
            if (j.error && typeof j.error === 'object' && j.error.msg) {
                return j.error.msg; // 新：{code,msg,detail}
            }
            if (j.message) {
                return j.message; // 旧：BaseException / API 代理
            }
        }
        if (fallback) {
            return fallback;
        }
        var status = httpStatus(src);
        if (status >= 400) {
            return 'HTTP ' + status;
        }
        return '请求失败';
    }

    /**
     * 取**机器可读**错误码（给前端做分支判断）。
     *
     * 注意与 `errorText` 的**刻意不对称**：这里 HTTP 状态码压过 `fallback`。
     * 因为 `fallback` 是调用方自己传的，返回它等于把调用方的入参原样还回去 —— 零信息量；
     * 而 `HTTP_502` 至少能区分"网关挂了"和"业务报错"。文案要兜底的场景用 `errorText`。
     */
    function errorCode(src, fallback) {
        var j = pick(src);
        if (j && typeof j === 'object') {
            if (j.error && typeof j.error === 'object' && j.error.code) {
                return j.error.code;
            }
            if (j._proxy_status) {
                return 'HTTP_' + j._proxy_status;
            }
        }
        var status = httpStatus(src);
        if (status) {
            return 'HTTP_' + status;
        }
        return fallback || 'UNKNOWN';
    }

    /**
     * 把 **fetch 的 `Response` + 已解析体** 打包成本层认得的输入形态。
     *
     * 为什么需要它：`fetch` 和 `$.ajax` 不一样 —— `res.json()` 会把 body **消费掉**，
     * 之后 `pick(res)` 只能拿到 `Response` 自己（它没有 `error` / `message` 字段），
     * 于是服务端文案会**丢**、只剩状态码。所以 fetch 的调用方必须把「状态」和「已解析体」
     * 一起交回来；本函数就是那个**唯一的规范形态**，别让各处自己拼 `{status, responseJSON}`。
     *
     * 用法：`ScaffoldApi.toError(ScaffoldApi.fromFetch(res, json), '请求失败')`
     */
    function fromFetch(res, json) {
        return { status: httpStatus(res), responseJSON: json };
    }

    /**
     * 把失败统一成一个 Error 形状的对象 —— 便于 `throw ScaffoldApi.toError(x)`。
     *
     * `detail` 必须一起带出来：本仓 `designer.js` 会读它做分支
     * （`e.detail?.reason`，见该文件「compactBlockedReason」那句）。旧实现是
     * `e.detail = err.detail`，缺省即 `undefined` —— 这里保持同样语义，不擅自改成 `[]`。
     */
    function toError(src, fallback) {
        var j = pick(src);
        var err = (j && typeof j === 'object' && j.error && typeof j.error === 'object') ? j.error : null;
        return {
            code: errorCode(src, fallback),
            msg: errorText(src, fallback),
            detail: err ? err.detail : undefined,
            http: httpStatus(src),
        };
    }

    window.ScaffoldApi = {
        pick: pick,
        httpStatus: httpStatus,
        fromFetch: fromFetch,
        isOk: isOk,
        data: data,
        errorText: errorText,
        errorCode: errorCode,
        toError: toError,
    };
})(window);
