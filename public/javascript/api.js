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
 * **迁移已收口（2026-09-19）**：本包自有的产出方（控制器 / 中间件 / 业务异常）**全部**上了信封，
 * 所以下面「非信封」那几类**不是迁移欠账、不能按旧形态删掉** —— 它们各有活产出方或永久契约。
 *
 * 后端形态对照：
 *   新信封：{ok:true, data:{…}}                    成功
 *           {ok:false, error:{code,msg,detail}} + HTTP 失败 —— 控制器 / 中间件 / 业务异常三类
 *
 *   ① **框架层**（永久例外，刻意不套）：`abort(404,'文案')` → `{message:"文案"}`（`src/` 里 20+ 处）、
 *      校验袋 `{message, errors}`、限流 `{message:"Too Many Attempts."}`。给它们套信封等于接管
 *      Laravel 的 exception handler，收益为零 ⇒ **`errorText()` 的 `j.message` 读法是契约、不是兼容**。
 *      后端一侧的守卫见 `tests/Feature/Http/FrameworkErrorShapeTest.php`。
 *   ② **API 代理**（`ApiProxyController`，上游 body 必须原样透传）：失败 `{_proxy_status:N, message}` ——
 *      HTTP 恒 200、真实状态在 body 里；成功可能是 `{data:<上游 body>}`，也可能上游关联数组直接作顶层。
 *   ③ **裸数据 / {ok:true,…payload}**：DesignerController 的早期形态，与代理共用 `data()` 的
 *      真值分支（`j.data ? j.data : j`；那条分支**刻意**保留真值判断，别"顺手统一"成 `!== undefined`）。
 *
 *   顶层字符串 `error`（`{error:"字符串"}`）这一支**已删**（2026-09-19）：它的最后两个产出方
 *   —— DocsController 与三个 Enforce* 中间件 —— 都迁进了信封，框架层与代理又不产它；
 *   `isOk()` 里那条「无状态码时把 `error` 键当失败」的兜底同时删除（判据见该函数注释）。
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
        // 框架层（`abort(404,'文案')` / 限流 429 / CSRF 419）与 API 代理的 body 里**没有成败标记**
        // —— 前者只有 `message`、后者靠 `_proxy_status`（HTTP 恒 200，见下面的分支）。
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

        // 走到这里都算成功。**曾有一条 `if (status === 0 && j.error) return false;` 的兜底**
        // （「拿不到状态码时把 `error` 键当失败信号」），2026-09-19 已删，两条理由：
        //   ① **不可达**：`isOk()` 全仓唯一调用点是 `designer.js._unwrap()`，它经
        //      `fromFetch(res, json)` 进来、**必然**带着 fetch 的数字状态码；
        //      本层的输入形态契约只有「jqXHR」与「fromFetch 打包」两种（见文件头），
        //      「无状态码的已解析 body」根本不是一种输入 ⇒ `status === 0` 只在 opaque / 网络错
        //      时出现，而那时 `pick()` 也拿不到 body。
        //   ② **判据本身不稳**：同一个 `{html, error:'frontmatter 警告'}`，带 200 时算成功
        //      （2xx 上的 `error` 是**领域字段**，见本地 Markdown 预览的形态）、
        //      不带状态码时算失败 —— 同一形状两个结论。判成败只该看「状态码 + body 的成败标记」，
        //      别再引入「`error` 键像不像失败」这类启发式。
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
        return j.data ? j.data : j; // 代理 / 早期形态：忠实复刻 `json.data ? json.data : json`（真值判断）
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
            if (j.error && typeof j.error === 'object' && j.error.msg) {
                return j.error.msg; // 新信封：{code,msg,detail}
            }
            // 顶层字符串 `error`（`{error:"字符串"}`）的读法**已于 2026-09-19 删除** ——
            // 最后两个产出方是 DocsController 与三个 Enforce* 中间件，两者都已迁入信封；
            // 框架层走下面的 `j.message`、代理走 `_proxy_status`，都不产这个形态。
            // 删掉是为了**让回退可见**：host 若还照旧写法返回 `{error:"…"}`，文案会落到
            // `fallback` / `HTTP <码>`（有信号），而不是被静默当作文案（看起来一切正常）。
            if (j.message) {
                // **框架层**（`abort()` 文案 / 校验袋 / `Too Many Attempts.`）+ API 代理 ——
                // 永久形态、不在信封迁移范围内，删了这句「文件已被修改，请重新打开后再编辑」
                // 这类排障文案会降级成 `HTTP 409`（后端侧守卫见 FrameworkErrorShapeTest）。
                return j.message;
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
