<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepSeek-backed Chinese → snake_case translator for field names, enum keys
 * and table-name shorthands.
 *
 * 失败模式：
 * - apiKey 空 → AiNotConfiguredException
 * - 非 2xx / JSON 不可解析 → AiUpstreamErrorException
 * - 单项校验失败 → results 项 valid=false（不抛错）
 */
class TranslationService
{
    private const FIELDS_SYSTEM_PROMPT = <<<'PROMPT'
你是数据库 schema 字段名生成器。我会给你一组中文字段描述，你为每一个生成最简洁的英文 snake_case 标识符。

规则（严格遵守）：
1. 输出必须是 snake_case：全小写、单词用下划线分隔、首字符必须是字母。
2. 总长度（含 prefix）不超过 64 字符（DB 标识符上限;能短则短,但带 prefix 的字段名按需用足）。
3. 复数动词、形容词等不要——只用名词（手机号→mobile / 头像→avatar / 个性签名→sign / 创建时间→created_at）。
4. 必须以 **user prompt 中给定的 prefix** 开头，严格使用它，**不要根据表名"修正"或自作主张换成你觉得对的 prefix**。
   - 例：prefix=user → user_avatar；prefix=oeder_user（即使看起来像拼错也照用）→ oeder_user_avatar；prefix=region 给"省份" → region_province。
   - 如果中文输入本身已经包含 prefix 语义（"用户头像"在 prefix=user 时），输出 user_avatar 而不是 user_user_avatar。
4b. **「通用:」标记词例外** —— 如果中文输入以「通用:」开头，**不要加 prefix**，只输出 snake_case 单词：
   - 输入「通用:角色 ID」 → output: `role_id`（无 prefix）
   - 输入「通用:创建人 ID」 → output: `creator_id`
   - 输入「通用:真实姓名」 → output: `real_name`
   - 这些是项目级通用字段（外键 / 公共引用），不属于当前表的私有字段。
   - 去掉「通用:」标记后再生成 snake_case 名称。comment 字段也去掉「通用:」前缀只保留原始描述。
5. 不得与已有字段列表重复（重复时返回 output=null + reason="重复字段名"）。
6. 输入如果已经是合规 snake_case 英文（匹配 ^[a-z][a-z0-9_]*$），原样返回（视作用户已手填）。
7. comment 字段填用户原始中文输入（去除空白）。
8. type 必须是下列之一：bigint / int / tinyint / varchar / char / text / tinytext / decimal / datetime / date / timestamp / bool / json
   选型指南：
   - 短文本（姓名/标题/编码/URL/路径）→ varchar
   - 长描述/内容/备注 → text
   - 时间字段（含 _at 后缀的）→ timestamp
   - 计数（数量/次数/登录次数）→ int
   - 状态/标记/性别/角色（小整数枚举）→ tinyint
   - 金额/价格 → decimal
   - 是否布尔 → bool
   - 配置/动态结构 → json
9. size 仅对 varchar / char 必填整数；常用值：
   - 短码/状态值/枚举 → 16-32
   - 姓名/标题 → 64-96
   - 手机号 → 20；邮箱 → 128
   - URL/路径/头像 → 192-255
   - 简介 → 255（超过用 text）
   其它类型（int/text/timestamp/json 等）size 必须为 null。

输出格式：严格的 JSON 对象，结构如下，不要任何额外文字：
{
  "results": [
    { "input": "姓名", "output": "user_name", "comment": "姓名", "type": "varchar", "size": 64 },
    { "input": "上次登录时间", "output": "last_login_at", "comment": "上次登录时间", "type": "timestamp", "size": null }
  ]
}

如果某项无法翻译（违反长度限制等），output / type / size 均设为 null，并附 reason 字段说明原因。
PROMPT;

    private const ENUMS_SYSTEM_PROMPT = <<<'PROMPT'
你是数据库枚举键名生成器。给定中文标签列表(一组同属一个字段的枚举值),生成英文 snake_case 键名 + 英文 Label,以 JSON 返回。

规则:
1. output(键名):snake_case 全小写、≤ 64 字符、首字符字母（硬上限 64;但枚举键务必短,见规则 2）。
2. **强制优先单个英文单词** — 能用一个单词表达就**绝不**用下划线拼接(枚举语义本身就短,多词冗长且跟项目风格不符)。
   - 状态/标记类一律单词:启用→enable(不是 is_enabled)、停用→disabled、未激活→inactive(不是 not_active)、激活→active、
     已删除→deleted(不是 is_deleted/has_deleted)、处理中→processing(不是 in_progress/in_processing)、
     待审核→pending(不是 wait_for_review/waiting_review)、有效→valid、失效→invalid、
     已完成→completed(不是 is_done)、失败→failed、成功→success、
     待支付→unpaid、已支付→paid、已取消→canceled、已退款→refunded。
   - 类型/角色类也一律单词:网页→web、移动端→mobile、桌面端→desktop、管理员→admin、访客→guest、超管→super。
   - 性别/方位/极性类:男→male、女→female、上→up、下→down、左→left、右→right、高→high、低→low。
   - **仅当单词无法准确表达且业界惯例就是多词时**才允许下划线(极少见,如 in_stock / out_of_stock 库存场景)。
   - 拿不准时优先短词,**绝不**为了"语义清晰"加 is_/has_/in_/not_ 前缀。
3. label_en(展示用英文标签):每个单词首字母大写(PascalCase 或 Title Case),≤ 24 字符。
4. 同组内 output 不得重复。
5. 输入已是合规英文 → 原样返回(视作用户已手填)。

JSON 输出格式:
{
  "results": [
    { "input": "未激活", "output": "inactive", "label_en": "Inactive" },
    { "input": "处理中", "output": "processing", "label_en": "Processing" }
  ]
}

不可翻译项 output / label_en 均为 null + reason 说明。
PROMPT;

    // 2026-05-21:字段拼写检查(不纠正,只标记疑似 typo)
    //   "json" 字面词出现在 JSON 输出格式段,DeepSeek json_object mode 守住
    private const SPELL_CHECK_SYSTEM_PROMPT = <<<'PROMPT'
你是数据库字段拼写检查器。给定一组英文 snake_case 字段名,逐个判断**拼写**是否正确,以 JSON 返回。

规则(严格遵守):
1. **只检查拼写,不评判命名风格** — 字段长度 / prefix 用法 / 命名约定都不在检查范围,**禁止把这些当 typo 标 false**。
2. 已是英文常见词(含技术词汇 url / api / json 等)→ `spelled_correctly: true`,无 suggestion / reason。
3. 明显 typo(如 `adress` → address、`recieve` → receive、`fimily` → family、`prefered` → preferred、`occurence` → occurrence)→ `spelled_correctly: false` + `suggestion`(修正后的字段名) + `reason`(简短说明哪个词错)。
4. **拼音 / 人名 / 项目代号 / 品牌名 / 缩写 / 业务领域词(如 sku / utm / qr / iban)→ 一律 `spelled_correctly: true`**,**绝不**当 typo。哪怕你不认识也归到 true,信任 user 命名。
5. 复合 snake_case 字段(如 user_first_name)按整体看,各 segment 单独判断:有任一 segment 是明显 typo → 整字段 false。
6. **绝对不要纠正** — 哪怕认为某词改写更好(如 phone_no → phone_number),只要原词拼写本身没错,一律 true。功能定位是**typo 标记器**,不是命名优化器。

JSON 输出格式:
{
  "results": [
    { "input": "user_address", "spelled_correctly": true },
    { "input": "user_adress",  "spelled_correctly": false, "suggestion": "user_address", "reason": "address 漏一个 d" }
  ]
}

results 数量必须跟输入项数一致(顺序对齐)。
PROMPT;

    private const TABLE_SHORT_SYSTEM_PROMPT = <<<'PROMPT'
你是数据库表名简写生成器。给定模块英文短名 + 表的中文名，生成符合本仓库约定的 snake_case 表名，以 JSON 返回。

约定：
1. 模块短名（小写）作为前缀，例如 module="user" / "system" / "tagging"。
2. 表名复数（用户表→user_users / 订单表→user_orders / 操作日志→system_operation_logs）。
3. 全 snake_case、长度不超过 64 字符（DB 标识符上限）。
4. 中文已包含模块语义时不重复（"用户" 在 user 模块里 → user_users 而不是 user_user_users）。

JSON 输出格式：
{
  "result": "user_users"
}

不可翻译时返回 { "result": null, "reason": "..." }。
PROMPT;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        // 传输 / 生成参数(借鉴 moo-scaffold-cloud,2026-06):AiSettingStore 注入,有默认值保持向后兼容。
        // temperature 低 → 翻译确定性高(不传时 DeepSeek 默认 1.0 偏高,同字段多次翻译会飘)。
        private readonly float $temperature = 0.2,
        private readonly int $maxTokens = 8192,
        private readonly int $connectTimeout = 8,
    ) {}

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    public function translateFieldNames(
        string $table,
        string $prefix,
        array $existingFields,
        array $chineseDescs,
        array $namingExamples = [],
        bool $lenient = false,
    ): array {
        $this->assertConfigured();

        $userPrompt = $this->buildFieldsUserPrompt($table, $prefix, $existingFields, $chineseDescs, $namingExamples, $lenient);
        $parsed     = $this->callJson(self::FIELDS_SYSTEM_PROMPT, $userPrompt);

        if (! isset($parsed['results']) || ! is_array($parsed['results'])) {
            throw new AiUpstreamErrorException('响应缺少 results 字段');
        }

        return ['results' => $this->validateFieldsResponse(
            $parsed['results'], $prefix, $existingFields, count($chineseDescs)
        )];
    }

    /**
     * 2026-05-21:enum 翻译加 namingExamples(本仓全局 enum 样本)— field naming 样本对 enum 命名维度
     * 不同,独立采集 + 独立喂给 AI 才能对齐本仓 enum 风格。
     *
     * @param array<int, array{field:string,key:string,label_en:string,label_zh:string}> $namingExamples
     */
    public function translateEnumKeys(string $field, array $chineseLabels, array $namingExamples = []): array
    {
        $this->assertConfigured();

        $userPrompt = $this->buildEnumsUserPrompt($field, $chineseLabels, $namingExamples);
        $parsed     = $this->callJson(self::ENUMS_SYSTEM_PROMPT, $userPrompt);

        if (! isset($parsed['results']) || ! is_array($parsed['results'])) {
            throw new AiUpstreamErrorException('响应缺少 results 字段');
        }

        return ['results' => $this->validateEnumsResponse($parsed['results'], count($chineseLabels))];
    }

    /**
     * 2026-05-21:字段拼写检查 — 不纠正,只标记疑似 typo。
     *
     * @param array<int, string> $fieldNames snake_case 字段名列表
     *
     * @return array{results: array<int, array{input:string, spelled_correctly:bool, suggestion?:?string, reason?:?string, valid:bool}>}
     */
    public function spellCheckFields(array $fieldNames): array
    {
        $this->assertConfigured();

        $userPrompt = "请为下列字段名做拼写检查:\n" . $this->numbered($fieldNames);
        $parsed     = $this->callJson(self::SPELL_CHECK_SYSTEM_PROMPT, $userPrompt);

        if (! isset($parsed['results']) || ! is_array($parsed['results'])) {
            throw new AiUpstreamErrorException('响应缺少 results 字段');
        }

        return ['results' => $this->validateSpellCheckResponse($parsed['results'], count($fieldNames))];
    }

    public function translateTableShort(string $module, string $chineseName): string
    {
        $this->assertConfigured();

        $userPrompt = "模块：{$module}\n中文表名：{$chineseName}";
        $parsed     = $this->callJson(self::TABLE_SHORT_SYSTEM_PROMPT, $userPrompt);

        $result = $parsed['result'] ?? null;
        if (! is_string($result) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $result)) {
            throw new AiUpstreamErrorException('table_short 响应非法：' . json_encode($parsed, JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------

    private function callJson(string $systemPrompt, string $userPrompt): array
    {
        try {
            // Round 2 P2 B-4:exponential backoff(500ms / 1000ms / 2000ms),只 retry 网络层异常
            // 不 retry 422/400 等业务错(when callback 卡死);5xx / timeout / connect-error 才重试。
            // Laravel 12 Http::retry 第二参传 array → 按 attempt index 取 sleep ms,实现指数退避
            $resp = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry([500, 1000, 2000], 0, function (\Throwable $e, $req) {
                    // ConnectionException / 5xx 才 retry,4xx 业务错不重试
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && $e->response->serverError());
                }, throw: false)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                    'model'           => $this->model,
                    'response_format' => ['type' => 'json_object'],
                    'temperature'     => $this->temperature,
                    'max_tokens'      => $this->maxTokens,
                    'messages'        => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);
        } catch (\Throwable $e) {
            // plan-40 §四 B-5 / B-8 + 2026-05-24 security audit P1:
            // 不要 leak upstream getMessage 全文(可能含 API key / endpoint / PII)— 包括 log。
            // 只 log class + code,'exception' => $e 仍保留供 Laravel error tracker 用(本地 dev 环境).
            Log::warning('DeepSeek HTTP 失败', [
                'exception_class' => get_class($e),
                'exception_code'  => $e->getCode(),
                'exception'       => $e,
            ]);
            throw new AiUpstreamErrorException('HTTP 调用失败，请检查 AI base_url 设置（/scaffold/config/ai）/ 网络；详情见 Laravel log');
        }

        if (! $resp->successful()) {
            // plan-40 §四 B-5:不要 leak $resp->body() 全文(429 body 可能含 request-id / org-id 等元数据)
            // 用 Laravel log 记 full body 供 dev 排错,toast 只给 status code
            Log::warning("DeepSeek upstream {$resp->status()}: " . $resp->body());
            $status = $resp->status();
            $hint   = $status === 429 ? '(限流,稍后重试)' : ($status >= 500 ? '(上游故障)' : '(请求被拒)');
            throw new AiUpstreamErrorException("DeepSeek 返回 {$status} {$hint};详情见 Laravel log");
        }

        $content = $resp->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new AiUpstreamErrorException('DeepSeek 响应缺少 message.content');
        }

        // 1) try raw json
        $parsed = json_decode($content, true);
        if (is_array($parsed)) {
            return $parsed;
        }
        // 2) DeepSeek/OpenAI 常用 ```json ... ``` markdown fence 包裹,strip 后重试
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $content, $m)) {
            $parsed = json_decode($m[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        // 3) 大括号块 fallback(AI 给了前后解释文字)
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        // plan-40 §四 B-5:JSON 不可解析也别 leak content 全文(可能含 prompt leak / 用户输入)
        Log::warning('DeepSeek JSON 不可解析: ' . $content);
        throw new AiUpstreamErrorException('DeepSeek 响应 JSON 不可解析；详情见 Laravel log');
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new AiNotConfiguredException('AI api_key 未配置，请在 /scaffold/config/ai 设置');
        }
    }

    // ---------------------------------------------------------------
    // Prompt builders
    // ---------------------------------------------------------------

    private function buildFieldsUserPrompt(string $table, string $prefix, array $existing, array $inputs, array $examples = [], bool $lenient = false): string
    {
        $head = '';
        if ($examples) {
            $lines = ['## 项目已有命名风格（模仿，理解 prefix 用法与单词偏好）：'];
            foreach ($examples as $ex) {
                $k = $ex['key']  ?? null;
                $n = $ex['name'] ?? null;
                if (is_string($k) && is_string($n)) {
                    $lines[] = "- {$n} → {$k}";
                }
            }
            $head = implode("\n", $lines) . "\n\n";
        }
        if ($lenient) {
            $head .= "## 宽松模式（覆盖规则 6 的\"放弃\"行为）\n"
                  . "即使中文是人名/品牌名/项目代号/缩写等无明确字段语义的词，**也必须翻译，不要放弃**。\n"
                  . "做法：优先用拼音（全拼或合理缩写）生成 snake_case，仍要带 prefix。\n"
                  . "例：prefix=user 时\"许大神\" → user_xudashen / user_xds；\"书进\" → user_shujin。\n\n";
        }
        $csv = implode(', ', $existing);

        return $head . "表名：{$table}\n字段 prefix：{$prefix}\n已有字段列表（不得重复）：{$csv}\n\n请为下列中文字段描述生成 snake_case 字段名：\n" . $this->numbered($inputs);
    }

    /**
     * 2026-05-21:enum user prompt — 喂全仓 enum 样本(field + key + label_zh)给 AI,
     * 让它模仿本仓既有风格,而不是凭空生成。
     *
     * @param array<int, array{field:string,key:string,label_en:string,label_zh:string}> $examples
     */
    private function buildEnumsUserPrompt(string $field, array $chineseLabels, array $examples = []): string
    {
        $head = '';
        if ($examples) {
            $lines = ['## 项目已有枚举风格(模仿语义 + key 命名习惯):'];
            foreach ($examples as $ex) {
                $f  = (string) ($ex['field'] ?? '');
                $k  = (string) ($ex['key'] ?? '');
                $zh = (string) ($ex['label_zh'] ?? '');
                $en = (string) ($ex['label_en'] ?? '');
                if ($f === '' || $k === '') {
                    continue;
                }
                $zhPart  = $zh !== '' ? " ({$zh})" : '';
                $enPart  = $en !== '' ? " / Label:{$en}" : '';
                $lines[] = "- {$f}.{$k}{$zhPart}{$enPart}";
            }
            $head = implode("\n", $lines) . "\n\n";
        }

        return $head . "字段:{$field}\n\n请为下列中文枚举标签生成 snake_case 键名:\n" . $this->numbered($chineseLabels);
    }

    private function numbered(array $items): string
    {
        $lines = [];
        foreach (array_values($items) as $i => $v) {
            $lines[] = ($i + 1) . '. ' . (string) $v;
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------
    // Response validation — §4.6
    // ---------------------------------------------------------------

    private function validateFieldsResponse(array $results, string $prefix, array $existing, int $expectedCount): array
    {
        $validated = [];
        $seen      = array_flip($existing);

        foreach ($results as $r) {
            $input  = $r['input']  ?? null;
            $output = $r['output'] ?? null;

            if (! is_string($input) || ($output !== null && ! is_string($output))) {
                $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => '响应结构异常'];

                continue;
            }
            if ($output === null) {
                $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => $r['reason'] ?? '模型放弃'];

                continue;
            }
            // 通用字段标记:input 以「通用:」开头 → 跳过 prefix 兜底(项目级公共字段,非当前表私有)
            // AI prompt 规则 4b 让模型自己输出 prefix-less,后端这里二次保险防 AI 误判加 prefix
            $isGeneric = is_string($input) && (str_starts_with($input, '通用:') || str_starts_with($input, '通用：'));
            if ($isGeneric && $prefix !== '' && str_starts_with($output, $prefix . '_')) {
                $output = substr($output, strlen($prefix) + 1);     // strip AI 误加的 prefix_
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $output)) {
                $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => "格式非法：{$output}"];

                continue;
            }
            // AI 没带 prefix 时:
            //   纯单词(无下划线)→ 后端兜底拼 prefix_;
            //   已含下划线 → 说明 AI 自作主张用了别的 prefix(常见于表名跟 prefix 拼写不一致的场景),直接 invalid,不要双拼
            // 通用字段标记跳过整个 prefix 兜底分支
            if (! $isGeneric && $prefix !== '' && ! str_starts_with($output, $prefix . '_') && $output !== $prefix) {
                if (str_contains($output, '_')) {
                    $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => "AI 输出 {$output},未以 prefix {$prefix}_ 开头(检查表 prefix 是否拼写正确)"];

                    continue;
                }
                $output = $prefix . '_' . $output;
                if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $output)) {
                    $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => "拼前缀后超长：{$output}"];

                    continue;
                }
            }
            if (isset($seen[$output])) {
                $validated[] = ['input' => $input, 'output' => null, 'valid' => false, 'reason' => '重复字段名'];

                continue;
            }
            // type / size 校验:type 必须在白名单;size 只对 varchar/char 有效
            $type    = $r['type'] ?? null;
            $size    = $r['size'] ?? null;
            $allowed = ['bigint', 'int', 'tinyint', 'varchar', 'char', 'text', 'tinytext', 'decimal', 'datetime', 'date', 'timestamp', 'bool', 'json'];
            if (! is_string($type) || ! in_array($type, $allowed, true)) {
                // AI 没给或给了非法 type,fallback varchar(64) — 字段是合规的,只是类型默认
                $type = 'varchar';
                $size = 64;
            }
            if (in_array($type, ['varchar', 'char'], true)) {
                if (! is_int($size) || $size < 1 || $size > 65535) {
                    $size = 64;
                }
            } else {
                $size = null;
            }
            $seen[$output] = true;
            // 通用字段:comment 也 strip 「通用:」前缀,保留原始中文描述
            $comment = (string) ($r['comment'] ?? $input);
            if ($isGeneric) {
                $comment = preg_replace('/^通用\s*[:：]\s*/u', '', $comment) ?? $comment;
            }
            $validated[] = [
                'input'   => $input,
                'output'  => $output,
                'comment' => $comment,
                'type'    => $type,
                'size'    => $size,
                'valid'   => true,
            ];
        }

        while (count($validated) < $expectedCount) {
            $validated[] = ['input' => null, 'output' => null, 'valid' => false, 'reason' => '响应缺项'];
        }

        return array_slice($validated, 0, $expectedCount);
    }

    /**
     * 2026-05-21:拼写检查响应校验 — 不严格(AI 标记 typo 边界宽松,留 client 决定要不要展示)
     *
     * @return array<int, array{input:?string, spelled_correctly:bool, suggestion:?string, reason:?string, valid:bool}>
     */
    private function validateSpellCheckResponse(array $results, int $expectedCount): array
    {
        $validated = [];
        foreach ($results as $r) {
            $input = $r['input']             ?? null;
            $ok    = $r['spelled_correctly'] ?? null;
            if (! is_string($input) || ! is_bool($ok)) {
                $validated[] = ['input' => $input, 'spelled_correctly' => true, 'suggestion' => null, 'reason' => null, 'valid' => false];

                continue;
            }
            $suggestion = isset($r['suggestion']) && is_string($r['suggestion']) ? $r['suggestion'] : null;
            $reason     = isset($r['reason'])     && is_string($r['reason']) ? $r['reason'] : null;
            // suggestion 必须 snake_case 才采纳;不合法忽略(但 spelled_correctly=false 仍保留,let client know)
            if ($suggestion !== null && ! preg_match('/^[a-z][a-z0-9_]*$/', $suggestion)) {
                $suggestion = null;
            }
            $validated[] = [
                'input'             => $input,
                'spelled_correctly' => $ok,
                'suggestion'        => $suggestion,
                'reason'            => $reason,
                'valid'             => true,
            ];
        }
        while (count($validated) < $expectedCount) {
            $validated[] = ['input' => null, 'spelled_correctly' => true, 'suggestion' => null, 'reason' => null, 'valid' => false];
        }

        return array_slice($validated, 0, $expectedCount);
    }

    private function validateEnumsResponse(array $results, int $expectedCount): array
    {
        $validated = [];
        $seen      = [];

        foreach ($results as $r) {
            $input   = $r['input']    ?? null;
            $output  = $r['output']   ?? null;
            $labelEn = $r['label_en'] ?? null;

            if (! is_string($input)) {
                $validated[] = ['input' => $input, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => '响应结构异常'];

                continue;
            }
            if ($output === null) {
                $validated[] = ['input' => $input, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => $r['reason'] ?? '模型放弃'];

                continue;
            }
            if (! is_string($output) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $output)) {
                $validated[] = ['input' => $input, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => "格式非法：{$output}"];

                continue;
            }
            if (isset($seen[$output])) {
                $validated[] = ['input' => $input, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => '同组重复'];

                continue;
            }
            if (! is_string($labelEn) || ! preg_match('/^[A-Z][A-Za-z0-9 ]{0,23}$/', $labelEn)) {
                $validated[] = ['input' => $input, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => 'label_en 非法'];

                continue;
            }
            $seen[$output] = true;
            $validated[]   = ['input' => $input, 'output' => $output, 'label_en' => $labelEn, 'valid' => true];
        }

        while (count($validated) < $expectedCount) {
            $validated[] = ['input' => null, 'output' => null, 'label_en' => null, 'valid' => false, 'reason' => '响应缺项'];
        }

        return array_slice($validated, 0, $expectedCount);
    }
}
