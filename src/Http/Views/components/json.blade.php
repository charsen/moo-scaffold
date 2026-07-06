@props([
    'data' => null,
    'pretty' => true,
    'highlight' => true,
])

@php
// 将任意 PHP 值序列化为 JSON 字符串
if (is_string($data)) {
    $raw = $data;
} else {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $raw = json_encode($data, $flags);
    if ($raw === false) {
        $raw = '<<unable to encode>>';
    }
}

// 简单语法高亮：识别 key / string / number / boolean / null / bracket
// 输出 HTML 安全：先 escape，再用 token 替换正则插入高亮 span
$html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($highlight && ! empty($html)) {
    $html = preg_replace_callback(
        '/(&quot;[^&]*?&quot;)(\s*:)?|\b(true|false|null)\b|\b(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\b|([\{\}\[\],])/u',
        static function ($m) {
            if (! empty($m[1])) {
                // 字符串（含 key）
                if (! empty($m[2])) {
                    return '<span class="json-block__token-key">' . $m[1] . '</span>' . $m[2];
                }
                return '<span class="json-block__token-string">' . $m[1] . '</span>';
            }
            if (! empty($m[3])) {
                $tone = $m[3] === 'null' ? 'null' : 'boolean';
                return '<span class="json-block__token-' . $tone . '">' . $m[3] . '</span>';
            }
            if (! empty($m[4])) {
                return '<span class="json-block__token-number">' . $m[4] . '</span>';
            }
            if (! empty($m[5])) {
                return '<span class="json-block__token-bracket">' . $m[5] . '</span>';
            }
            return $m[0];
        },
        $html
    );
}
@endphp

<pre {{ $attributes->class(['json-block']) }}>{!! $html !!}</pre>
