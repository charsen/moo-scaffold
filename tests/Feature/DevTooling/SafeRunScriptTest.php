<?php

declare(strict_types=1);

/**
 * `tests/Browser/safe-run.sh`（e2e 的宿主还原脚本）的结构守卫。
 *
 * **为什么这个脚本值得一个守卫**：它的输出**就是**每次 e2e 结论的可信度来源 ——
 * 「A/B 有没有回归」这一步建立在「跑完宿主已还原」之上。2026-09-20 实测它会**说谎**：
 * 清理段第一步 `git -C "$HOST_DB_PATH" checkout . >/dev/null 2>&1` 失败时**无声无息**，
 * 脚本照常打「已清理本次新增的未跟踪产物 3 项」，而宿主的 `engine/scaffold/database/Platform.yaml`
 * 仍留在 ` M`（手工重跑同一条命令立刻成功）。
 *
 * **为什么只能扫源码守**：没有任何测试会去跑 e2e 的收尾段 —— 把回滚改回静默、或删掉自证，
 * 行为用例照样全绿（脚本照样退出 0）。这一类「工具自己说谎」的回归只有源码锚点挡得住。
 */
function safeRunScriptPath(): string
{
    return dirname(__DIR__, 2) . '/Browser/safe-run.sh';
}

/**
 * 只留**可执行行**（剥掉 `#` 注释行）。
 *
 * 为什么要剥：这个脚本的注释里**必须**能引用旧写法当反面教材（文件头就写着
 * 「旧写法是 `git -C "$HOST_DB_PATH" checkout . >/dev/null 2>&1`」），
 * 不剥的话「反例不许出现」这条断言会被脚本自己的文档绊倒。
 */
function safeRunCode(): string
{
    $lines = explode("\n", (string) file_get_contents(safeRunScriptPath()));

    return implode("\n", array_filter(
        $lines,
        fn (string $line): bool => ! str_starts_with(ltrim($line), '#')
    ));
}

it('safe-run.sh 语法合法（bash -n）', function () {
    $out = [];
    $rc  = 0;

    exec('bash -n ' . escapeshellarg(safeRunScriptPath()) . ' 2>&1', $out, $rc);

    // 把 bash 的抱怨塞进断言值本身：Pest 的 toBe 没有「说明」第二参，
    // 这样失败时才看得到究竟哪一行语法坏了。
    expect($rc === 0 ? 'OK' : implode("\n", $out))->toBe('OK');
});

it('清理段的失败不许静默：回滚与删产物都要说话', function () {
    $code = safeRunCode();

    // ① 反例：旧写法把 stderr 一起吞了 —— 这正是本次事故的根因，不许在可执行代码里复活
    expect($code)->not->toContain('checkout . >/dev/null 2>&1');

    // ② 正例：回滚要「捕获输出 + 只在失败时说话」，并把 git 的原话透出来
    expect($code)
        ->toContain('if ! checkout_err="$(git -C "$HOST_DB_PATH" checkout')
        ->toContain('2>&1)"; then')
        ->toContain('回滚已跟踪文件失败')
        ->toContain('git: $checkout_err')
        // ③ 删未跟踪产物同理：删不掉必须单独报（否则会和「已清理 N 项」一起被读成清理成功）
        ->toContain('⚠ 清理失败：$path')
        ->toContain('$rm_err');
});

it('跑完要自证「宿主已还原」：差集式残留检查，而不是打一句话当中立', function () {
    expect(safeRunCode())
        // 跑前记下该层「本来就脏」的清单 —— 自证拿它做差集，开发者已有的未提交改动不会误报
        ->toContain('DB_BASELINE')
        ->toContain('grep -Fxq -- "$p" "$DB_BASELINE"')
        // 判残留的 pathspec 只落在 scaffold/database 那一层
        ->toContain('-- "$DB_REL"')
        // 残留必须被明确报出来
        ->toContain('仍有未还原的改动')
        // 该层相对仓根的路径交给 git 算：自己拼前缀会被 macOS /tmp → /private/tmp 软链坑到
        // （实测 $REPO_ROOT=/private/tmp/... 而传入的是 /tmp/...）
        ->toContain('rev-parse --show-prefix');
});
