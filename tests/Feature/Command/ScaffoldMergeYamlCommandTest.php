<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * moo:scaffold:merge-yaml 特征测试。
 *
 * 收口前本命令**零测试覆盖** —— 它由 scaffold-sync.sh 在 rebase 冲突时调用，平时跑不到，
 * 而它的 `absolutePath()` 只判 `$path[0] === '/'`（连 Windows 盘符都不认，也不 ltrim），
 * 是第 6 项路径归一里唯一带真 bug 的一份。收口到 `Support\Paths::absolute()` 后补上这个缺口。
 *
 * 造冲突 index 的办法：临时 `git init` 仓 + `git hash-object -w` + `git update-index --cacheinfo`
 * 直接写 stage 2（ours）/ 3（theirs），不必真跑一次 merge/rebase，也不碰本仓。
 *
 * 鉴别力集中在「相对路径按 git 仓根展开」：把 CWD 切到仓内子目录再传相对路径 —— 若实现改成按 CWD
 * 展开（AuditFormerTypes 那种语义），这里就会报「文件不存在」而不是成功。
 */
$GLOBALS['mooMergeYamlFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['mooMergeYamlFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['mooMergeYamlFixtures'] = [];
});

/** 在 $repo 里跑一条 git 命令；失败即抛，避免测试静默绿。 */
function mergeYamlGit(string $repo, array $args, string $input = ''): string
{
    $proc = new Process($args, $repo);
    if ($input !== '') {
        $proc->setInput($input);
    }
    $proc->run();

    if (! $proc->isSuccessful()) {
        throw new RuntimeException('git ' . implode(' ', $args) . ' 失败：' . $proc->getErrorOutput());
    }

    return $proc->getOutput();
}

/**
 * 把两份内容作为一个「冲突 index entry」写进 $repo（stage 2 = ours / 3 = theirs）。
 *
 * 用 `--index-info`（stdin 走 `mode SP sha SP stage TAB path`）而不是 `--cacheinfo` ——
 * `--cacheinfo` 的首段是 **mode**（如 100644），写不出非 0 stage。
 */
function mergeYamlStages(string $repo, string $path, string $ours, string $theirs): void
{
    $blob = static fn (string $content): string => trim(mergeYamlGit($repo, ['git', 'hash-object', '-w', '--stdin'], $content));

    mergeYamlGit(
        $repo,
        ['git', 'update-index', '--index-info'],
        "100644 {$blob($ours)} 2\t{$path}\n100644 {$blob($theirs)} 3\t{$path}\n",
    );
}

/** 一个 accounts.yaml 冲突的 YAML 片段（meta.updated_at 决定仲裁）。 */
function mergeYamlAccounts(string $who, string $at): string
{
    return "# {$who}\n"
        . "meta:\n"
        . "    schema_version: 1\n"
        . "    updated_at: '{$at}'\n"
        . "accounts:\n"
        . "    - username: {$who}\n"
        . "      password: hash-{$who}\n"
        . "      updated_at: '{$at}'\n";
}

/**
 * 造临时 git 仓，含一个「冲突中」的文件（工作树里也放一份，满足 file_exists 前置）。
 *
 * @return array{repo: string, root: string, rel: string, abs: string}
 */
function mergeYamlFixture(
    ?string $ours = null,
    ?string $theirs = null,
    string $rel = 'scaffold/accounts.yaml',
): array {
    $repo = sys_get_temp_dir() . '/moo-merge-' . bin2hex(random_bytes(4));
    mkdir(dirname($repo . '/' . $rel), 0777, true);
    $GLOBALS['mooMergeYamlFixtures'][] = $repo;

    mergeYamlGit($repo, ['git', 'init', '-q']);

    // git 报出来的 toplevel 才是命令实际用的仓根（macOS 上 sys_get_temp_dir() 可能是 /var/... 符号链，
    // git 会解析成 /private/var/...）。绝对路径用例必须基于它构造，否则 relativeToRoot 对不上。
    $root = trim(mergeYamlGit($repo, ['git', 'rev-parse', '--show-toplevel']));

    $mine   = $ours   ?? mergeYamlAccounts('alice', '2026-01-01 00:00:00');
    $theirs = $theirs ?? mergeYamlAccounts('alice', '2026-02-02 00:00:00');

    mergeYamlStages($repo, $rel, $mine, $theirs);
    file_put_contents($repo . '/' . $rel, $mine);

    return ['repo' => $repo, 'root' => $root, 'rel' => $rel, 'abs' => $root . '/' . $rel];
}

/** 在 $cwd 下跑命令，跑完还原 CWD（chdir 是进程级的，不还原会污染其它用例）。 */
function mergeYamlRun(string $cwd, array $args): array
{
    $prev = getcwd();
    chdir($cwd);

    try {
        $code = Artisan::call('moo:scaffold:merge-yaml', $args);
        $out  = Artisan::output();
    } finally {
        chdir($prev);
    }

    return [$code, $out];
}

it('相对路径按 git 仓根展开：CWD 在仓内子目录也能读到冲突双方并合并', function () {
    $fx = mergeYamlFixture();
    mkdir($fx['repo'] . '/sub');

    [$code, $out] = mergeYamlRun($fx['repo'] . '/sub', ['path' => $fx['rel'], '--dry-run' => true]);

    expect($code)->toBe(0)
        // theris 的 updated_at 更晚 → 取 theirs，且 meta 被改写成 auto-merge 现场
        ->and($out)->toContain('sync:auto-merge')
        ->and($out)->toContain('merge:last-write-wins')
        ->and($out)->toContain('username: alice');
});

it('相对路径指向仓内不存在的文件 → 退出码 1 且明说「文件不存在」', function () {
    $fx = mergeYamlFixture();

    [$code, $out] = mergeYamlRun($fx['repo'], ['path' => 'scaffold/nope.yaml']);

    expect($code)->toBe(1)->and($out)->toContain('文件不存在');
});

it('相对路径按仓根展开（不按 CWD）：仓根相对名 + CWD 在别处 → 按 CWD 展开会找不到', function () {
    $fx = mergeYamlFixture();

    // CWD 用仓内子目录：若实现按 CWD 展开，拼出来的是 <repo>/sub/scaffold/accounts.yaml → 不存在。
    mkdir($fx['repo'] . '/sub');
    [$codeCwd, $outCwd] = mergeYamlRun($fx['repo'] . '/sub', ['path' => $fx['rel'], '--dry-run' => true]);
    expect($codeCwd)->toBe(0)->and($outCwd)->not->toContain('文件不存在');

    // 反向锚点：同一个相对名在 CWD 下确实不存在，证明上面那条有鉴别力。
    expect(file_exists($fx['repo'] . '/sub/' . $fx['rel']))->toBeFalse();
});

it('绝对路径原样使用：即使在另一个仓里跑，也按原值定位（不被拼上当前仓根）', function () {
    $fx      = mergeYamlFixture();
    $outside = $fx['repo'] . '/../moo-merge-outside-' . bin2hex(random_bytes(4)) . '.yaml';

    // CWD 放到**另一个** git 仓：若实现把绝对路径当相对路径拼仓根，回显会变成 <otherRoot>/<outside>。
    $other        = mergeYamlFixture();
    [$code, $out] = mergeYamlRun($other['repo'], ['path' => $outside]);

    expect($code)->toBe(1)
        // 回显的是原样绝对路径；若被当相对路径拼仓根，`文件不存在：` 后面会先接仓根前缀。
        ->and($out)->toContain("文件不存在：{$outside}")
        ->and($out)->not->toContain('文件不存在：' . rtrim($other['root'], '/') . '/' . ltrim($outside, '/'))
        ->and($out)->not->toContain('文件不存在：' . rtrim($fx['root'], '/') . '/' . ltrim($outside, '/'));
});

it('文件在但不在冲突状态（无 stage 2/3）→ 退出码 1 且指向「不在 rebase/merge 状态」', function () {
    $fx = mergeYamlFixture();
    // 清空 index（所有 stage 一起没）→ 等价于「未冲突 / 已解决」
    mergeYamlGit($fx['repo'], ['git', 'read-tree', '--empty']);

    [$code, $out] = mergeYamlRun($fx['repo'], ['path' => $fx['rel']]);

    expect($code)->toBe(1)
        ->and($out)->toContain('无法读取冲突双方')
        ->and($out)->not->toContain('文件不存在');
});

it('当前目录不在任何 git 仓内 → 退出码 1 且明说（仓根探测走 GitInspector::repoRootOrNull）', function () {
    // 2026-09-18 收口前这里零覆盖:原实现是 Process::fromShellCommandline 手工判失败返回 null。
    // 现在走 GitInspector,这条锁住「不在仓内 = null → 命令报错退出」这条契约没被改动。
    $dir = sys_get_temp_dir() . '/moo-merge-norepo-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $GLOBALS['mooMergeYamlFixtures'][] = $dir;

    [$code, $out] = mergeYamlRun($dir, ['path' => 'accounts.yaml']);

    expect($code)->toBe(1)->and($out)->toContain('当前不在 git 仓库内');
});
