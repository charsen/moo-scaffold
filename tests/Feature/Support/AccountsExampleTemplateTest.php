<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * `stubs/accounts.example.yaml`（**随包发布**的账号模板）的内容锚点。
 *
 * **为什么钉这个**：该文件入 git、随 composer 包发到每一个宿主，文件头自己就写着
 * 「本模板入 git，请勿写入真实密码」。2026-09-20 实测它的示例口令是 `'123654'`
 * —— 6 位纯数字，**而且注释里还把这个值抄了一遍**。在一个公开仓库里，
 * 一个「形态与真口令无法区分」的字符串就是可被照抄的凭据。
 * 更关键的是：这个示例值**恰好就是**本机宿主测试账号当时在用的口令 ⇒
 * 「包内示例默认值会被真人默认采用」在本项目是**实测事实**，不是理论担忧。
 *
 * **为什么只能扫内容守**：没有任何测试会校验这个文件 —— stub 只是给人看的模板
 * （运行时读写的是宿主里的 `scaffold/accounts.yaml`；全仓搜 `accounts.example`
 * 只有 `AccountStore.php` 一条路径注释）。所以把真口令塞回去，行为用例照样全绿。
 *
 * **判定口径刻意宽松**：不钉「必须等于某个字面量」（那样换个占位词就要改测试），
 * 而是钉三条：① 有占位语义 ② 不是纯数字 ③ 注释里也不许抄进纯数字字面量。
 *
 * ⚠️ 失败信息**一律不回显口令值**（连长度都不给）—— 否则真有人写进真口令时，
 * CI 日志反而变成新的泄漏面。只给行号，值让看的人自己去文件里核对。
 */
function acctTpl_path(): string
{
    return dirname(__DIR__, 3) . '/stubs/accounts.example.yaml';
}

function acctTpl_source(): string
{
    $path = acctTpl_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** 解析后的示例口令。解析失败返回 null（由用例 1 负责报出来）。 */
function acctTpl_password(): ?string
{
    $doc = Yaml::parse(acctTpl_source());

    if (! is_array($doc) || ! is_array($doc['accounts'] ?? null) || ! is_array($doc['accounts'][0] ?? null)) {
        return null;
    }

    $pw = $doc['accounts'][0]['password'] ?? null;

    return is_scalar($pw) ? (string) $pw : null;
}

it('账号模板结构完好：能解析、accounts 非空、示例条目带 password', function () {
    // 这条是「挡守卫空转」用的：如果文件被删 / 被改名 / accounts 变空，
    // 下面两条断言会变成对 null 的空转（PHP 里 null 传进 preg_match 直接是 0/false），
    // 于是「占位断言」全绿却什么都没守住。先把它钉死。
    $src = acctTpl_source();

    expect($src)->not->toBe('', '模板不见了：' . acctTpl_path());

    $doc = Yaml::parse($src);

    expect($doc)->toBeArray('模板不再是合法 YAML')
        ->and($doc['accounts'] ?? null)->toBeArray()
        ->and($doc['accounts'])->not->toBe([], '模板里一个示例条目都没有 —— 下面的占位断言会空转')
        ->and($doc['accounts'][0])->toHaveKey('password');

    // 文件头那句警告是「不许写真密码」这个约定的唯一落点，删掉它等于约定失效
    expect($src)->toContain('请勿写入真实密码');
});

it('示例口令必须是「看得出来的占位符」，不许是像真口令的字符串', function () {
    $pw = acctTpl_password();

    expect($pw)->not->toBeNull('取不到示例口令 —— 见用例 1 的结构报错')
        // 不能为空：模板要示意「这里放明文，store 首次写入会自动 bcrypt 化」
        ->and($pw)->not->toBe('', 'password 一栏不能空 —— 模板要示意这里是明文');

    // ① 纯数字（`123654` / `123456` / `888888` 这类 PIN 形态）：首禁 ——
    //    它最容易被人当成「反正能用」直接沿用，也最难在日常 review 里被看出是凭据。
    expect(preg_match('/^\d+$/', (string) $pw))->toBe(0,
        '示例口令是纯数字形态 —— 与真口令无法区分，且最容易被真人照抄。请改成 change-me 这类明确占位。');

    // ② 必须自带「占位」语义：读的人一眼就知道「这个要改」，而不是「这个可能能登录」。
    expect(preg_match('/change|placeholder|your|xxx|todo|example/i', (string) $pw))->toBe(1,
        '示例口令看不出是占位符。请写成 change-me / your-password 这类一眼就知道要改的值（值已刻意不回显）。');
});

it('源码层锚点：注释里也不许抄进「引号包起来的 ≥6 位纯数字」', function () {
    // 本次缺陷的**完整**形态是两处：`password: '123654'` **加上**注释那句「此处明文 '123654' …」。
    // 只改 password 栏挡不住注释 —— 这条与解析结果无关，专挡「值改干净了、注释还留着」。
    $where = [];

    foreach (explode("\n", acctTpl_source()) as $i => $line) {
        if (preg_match('/[\'"][0-9]{6,}[\'"]/', $line) === 1) {
            $where[] = '第 ' . ($i + 1) . ' 行';
        }
    }

    expect($where)->toBe([], '模板里出现「引号包起来的 ≥6 位纯数字」字面量（'
        . implode('、', $where) . '）。该形态与真口令无法区分，而本文件随包发布 —— '
        . '请删掉或改成明确占位。值已刻意不回显，请自行到文件里核对。');
});
