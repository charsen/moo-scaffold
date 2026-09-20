<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Support\AiSettingStore;
use Mooeen\Scaffold\Support\Concerns\InteractsWithConsoleUi;
use Mooeen\Scaffold\Support\Concerns\SharedCodegenHelpers;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Support\DocsRepository;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 钉死「文件写入失败必须被感知」这条线（2026-09-18）。
 *
 * 背景：`Filesystem::put()` / `append()` 返回的是 `file_put_contents()` 的结果 ——
 * **`int|false`**（写成功 = 写入字节数，失败才是 `false`），**不是 bool**。此前 19 处调用点
 * 直接丢弃返回值：磁盘满 / 权限被拒时 `put()` 返回 false，代码却继续打 `created()` 绿字，
 * 用户以为生成成功。这跟命令层「屏幕上打红字『失败』、退出码却是 0」是同一类故障 ——
 * **屏幕口径 ≠ 真实结果**。
 *
 * 本文件四层覆盖：
 *   ① 助手 `SharedCodegenHelpers::putOrReport()` 的三态（成功静默 / 失败上报 / 0 字节算成功）；
 *   ② 另一条同族路径 `FreshStorageGenerator::reportPutResult()`（silence 语义不同，未并入助手）；
 *   ③ `Utility::addGitIgnore()` 的失败分支（2026-09-19 起该方法的输出出口收成 `ConsoleUi` 参数）；
 *   ④ Web/Support 层 3 个 store —— 无 console，写失败/删失败一律抛异常（由 Controller 的 catch 落 4xx）；
 *   ⑤ 结构不变式：`src/` 下每一处文件写入（`put`/`append`）与删除（`delete`）都必须带失败判定
 *      （含已知欠账白名单，当前为空）。
 */

/** 写失败的文件系统：put()/append()/delete() 一律返回 false —— 与 file_put_contents / unlink 的失败态一致。 */
function wfg_failing_fs(): Filesystem
{
    return new class extends Filesystem
    {
        public function put($path, $contents, $lock = false)
        {
            return false;
        }

        public function append($path, $data, $lock = false)
        {
            return false;
        }

        public function delete($paths)
        {
            return false;
        }
    };
}

/**
 * `putOrReport` 的宿主：trait 的隐式依赖（`protected Filesystem $filesystem` + `console()`）
 * 在匿名类里补齐。`getConsoleTarget()` 收窄成 `OutputInterface` 是**窄化**（基类声明是
 * `ConsoleCommand|Factory|OutputInterface` 联合），合法；反过来加宽会 Fatal。
 */
function wfg_subject(Filesystem $fs, BufferedOutput $buffer): object
{
    return new class($fs, $buffer)
    {
        use InteractsWithConsoleUi;
        use SharedCodegenHelpers;

        public function __construct(protected Filesystem $filesystem, private BufferedOutput $sink) {}

        protected function getConsoleTarget(): OutputInterface
        {
            return $this->sink;
        }

        public function callPutOrReport(string $file, string $relative, string $content): bool
        {
            return $this->putOrReport($file, $relative, $content);
        }
    };
}

// ─── ① putOrReport 三态 ────────────────────────────────────────────────────

it('putOrReport:写成功 → 返回 true 且不打任何输出（成功行归调用点）', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_wfg_' . uniqid() . '.txt';

    expect(wfg_subject(new Filesystem, $buffer)->callPutOrReport($file, './rel/ok.txt', 'hello'))->toBeTrue();
    expect($buffer->fetch())->toBe('');
    expect(file_get_contents($file))->toBe('hello');

    @unlink($file);
});

it('putOrReport:写失败 → 返回 false + 打 failed 且带相对路径', function () {
    $buffer = new BufferedOutput;

    $ok = wfg_subject(wfg_failing_fs(), $buffer)
        ->callPutOrReport('/unwritable/a.php', './unwritable/a.php', 'x');

    expect($ok)->toBeFalse();

    $out = $buffer->fetch();
    expect($out)->toContain('unwritable/a.php');
    expect($out)->toContain('写入失败');
});

it('putOrReport:成功写入 0 字节 → true（钉死 int|false 语义，真值判断会误判成失败）', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_wfg_zero_' . uniqid() . '.txt';

    // file_put_contents 写空串返回 0 —— `if (! $put)` 会把它读成「写失败」
    expect(wfg_subject(new Filesystem, $buffer)->callPutOrReport($file, './rel/zero.txt', ''))->toBeTrue();
    expect($buffer->fetch())->toBe('');
    expect(file_get_contents($file))->toBe('');

    @unlink($file);
});

// ─── ② reportPutResult（FreshStorageGenerator 自有路径，带 silence 语义）────────

it('reportPutResult:0 字节算写成功 → 报 updated/created，只有真 false 才报 failed', function () {
    $buffer = new BufferedOutput;
    $gen    = new FreshStorageGenerator($buffer, new Filesystem, app(Utility::class));

    $m = new ReflectionMethod($gen, 'reportPutResult');
    $m->setAccessible(true);

    // 0 = 写成功但 0 字节（file_put_contents 的返回值），必须走成功分支
    $m->invoke($gen, './rel/zero.yaml', 0, true);
    expect($buffer->fetch())->toContain('Updated')->not->toContain('写入失败');

    $m->invoke($gen, './rel/zero-new.yaml', 0, false);
    expect($buffer->fetch())->toContain('Created')->not->toContain('写入失败');

    // 只有严格的 false 才是失败
    $m->invoke($gen, './rel/fail.yaml', false, false);
    expect($buffer->fetch())->toContain('rel/fail.yaml');
});

// ─── ③ Utility::addGitIgnore（2026-09-19 起收 ConsoleUi 参数）────────────────────

it('Utility::addGitIgnore:写失败 → 打 failed 而不是 created', function () {
    $utility = new Utility;
    $prop    = new ReflectionProperty($utility, 'filesystem');
    $prop->setAccessible(true);
    $prop->setValue($utility, wfg_failing_fs());

    // 该文件已存在时整个分支会被跳过（连写入都不做），先确保它不存在
    @unlink(storage_path('scaffold/.gitignore'));

    $buffer = new BufferedOutput;
    $utility->addGitIgnore(new ConsoleUi($buffer));

    $out = $buffer->fetch();
    expect($out)->toContain('写入失败');
    expect($out)->not->toContain('Created');
});

// ─── ④ Web/Support 层 store：无 console，写失败必须抛异常 ──────────────────────
//
// 这 4 个写入点（AccountStore::writeYaml / AiSettingStore::writeYaml /
// DocsRepository::save·reorder）与 CLI 侧不是同一套语义：本层没有 console() 可打 failed，
// 上游是 HTTP Controller。静默吞掉 → 用户看到「已保存」绿条 / 200，磁盘上还是旧内容。
// 口径 = 抛 RuntimeException，由 Controller 的 `catch (\Throwable $e)` 落 4xx（message 直给用户）。

/** 临时沙箱：切 base_path 到 temp 目录，跑完必还原并删目录（store 路径都落在 base_path 下）。 */
function wfg_sandbox(callable $fn): void
{
    $dir = sys_get_temp_dir() . '/moo_wfg_' . uniqid();
    @mkdir($dir, 0755, true);
    $orig = base_path();
    app()->setBasePath($dir);

    try {
        $fn($dir);
    } finally {
        app()->setBasePath($orig);
        (new Filesystem)->deleteDirectory($dir);
    }
}

it('AccountStore::create():写盘失败 → 抛异常（控制器落 flash_error），不静默成功', function () {
    wfg_sandbox(function () {
        config(['scaffold.accounts.yaml_path' => 'accounts.yaml']);
        app()->instance(Filesystem::class, wfg_failing_fs());

        $store = app(AccountStore::class);

        expect(fn () => $store->create(['username' => 'wfg', 'password' => 'x', 'role' => 'admin'], 'test'))
            ->toThrow(RuntimeException::class, '写入失败');

        expect($store->exists())->toBeFalse();   // 没有任何半成品落盘
    });
});

it('AiSettingStore::save():写盘失败 → 抛异常（控制器落 flash_error），不静默成功', function () {
    wfg_sandbox(function () {
        config(['scaffold.ai.yaml_path' => 'ai.yaml']);
        app()->instance(Filesystem::class, wfg_failing_fs());

        expect(fn () => app(AiSettingStore::class)->save(['model' => 'gpt-x']))
            ->toThrow(RuntimeException::class, '写入失败');
    });
});

it('DocsRepository::save():写盘失败 → 抛异常且 message 带 slug（落 422 JSON 给编辑器）', function () {
    wfg_sandbox(function () {
        config(['scaffold.docs.path' => 'docs']);
        app()->instance(Filesystem::class, wfg_failing_fs());

        expect(fn () => app(DocsRepository::class)->save('随手记', "正文\n"))
            ->toThrow(RuntimeException::class, '随手记');
    });
});

it('DocsRepository::reorder():写盘失败 → 抛异常，且该篇 order 行未落地（无半套编号）', function () {
    wfg_sandbox(function ($dir) {
        config(['scaffold.docs.path' => 'docs']);

        // 先用真实 fs 造两篇（都不带 frontmatter → order 取默认 999，重排必触发写入）
        $real = new DocsRepository(new Filesystem, app(Utility::class));
        $real->save('a', "正文A\n");
        $real->save('b', "正文B\n");

        app()->instance(Filesystem::class, wfg_failing_fs());

        expect(fn () => app(DocsRepository::class)->reorder(['b', 'a']))
            ->toThrow(RuntimeException::class, '写入失败');

        // 第一篇 b 的写入就失败了 → 文件必须还是原样（没被插进 order: 行）
        expect(file_get_contents($dir . '/docs/b.md'))->toBe("正文B\n");
    });
});

it('DocsRepository::delete():删不掉 → 抛异常（控制器落 422），不静默回 200', function () {
    wfg_sandbox(function ($dir) {
        config(['scaffold.docs.path' => 'docs']);

        $real = new DocsRepository(new Filesystem, app(Utility::class));
        $real->save('待删', "正文\n");

        // 只让 delete() 失败：isFile() / withinBase() 照走真 FS ⇒ 复刻「读得到、删不掉」现场
        app()->instance(Filesystem::class, wfg_failing_fs());

        expect(fn () => app(DocsRepository::class)->delete('待删'))
            ->toThrow(RuntimeException::class, '删除失败');

        expect(file_exists($dir . '/docs/待删.md'))->toBeTrue();   // 文件确实还在（没被假成功骗过）
    });
});

// ─── ⑤ 结构不变式 ──────────────────────────────────────────────────────────

it('结构不变式：src/ 下每一处文件写入 / 删除都带失败判定（不留静默丢弃）', function () {
    $src = __DIR__ . '/../../../src';

    /*
     * 已知欠账白名单 —— 当前**空**。
     * 2026-09-18：曾钉住 Web/Support 层 4 处 `$this->fs->put()`（AccountStore /
     * AiSettingStore / DocsRepository ×2）。它们与 CLI 侧不是同一套错误语义（没有
     * console 可打 failed，正确修法是抛异常），已按此修完，条目全部删除。
     * 机制保留：将来若再发现同类欠账，可临时钉在这里，但白名单越短越好。
     */
    $knownDebt = [];

    $offenders = [];

    foreach ((new Filesystem)->allFiles($src) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($src . '/', '', $file->getPathname());
        $lines    = explode("\n", (string) file_get_contents($file->getPathname()));

        foreach ($lines as $i => $line) {
            // 只认文件系统写入/删除：`$this->filesystem->put(` / `$this->fs->put(` / `->append(` / `->delete(`
            // —— 刻意不匹配 cache()->put / session()->put（那是另一套语义）
            if (! preg_match('/->(fs|filesystem)->(put|append|delete)\(/', $line)) {
                continue;
            }

            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                continue;   // 注释 / docblock 里提一嘴不算
            }

            $pass = str_contains($line, '=== false')
                || str_contains($line, '!== false')
                || str_contains($line, 'putOrReport(');

            // `delete()` 返回**纯 bool**（不是 put 那种 `int|false`），所以不存在「真值判断把 0 字节
            // 当成失败」的陷阱 ⇒ 判定放宽成「返回值被消费」：直接做 if 条件（可带 !）或赋给变量。
            // 写成 `if (...->delete($x))` / `if (! ...->delete($x))` 都算过；裸语句 `$this->fs->delete($x);` 不算。
            if (! $pass) {
                $pass = (bool) preg_match('/\bif\s*\(\s*!?\s*\$this->(fs|filesystem)->delete\(/', $line)
                    || (bool) preg_match('/= *\$this->(fs|filesystem)->delete\(/', $line);
            }

            // 允许「先收结果、随后交给 reportPutResult」这一种形态（FreshStorageGenerator 2 处）
            if (! $pass) {
                $tail = implode("\n", array_slice($lines, $i + 1, 5));
                $pass = str_contains($tail, 'reportPutResult(');
            }

            if (! $pass && in_array([$relative, $trimmed], $knownDebt, true)) {
                $pass = true;
            }

            if (! $pass) {
                $offenders[] = $relative . ':' . ($i + 1) . '  ' . $trimmed;
            }
        }
    }

    expect($offenders)->toBe([], "以下写入/删除点丢弃了 put()/append()/delete() 的返回值，失败会被静默吞掉：\n" . implode("\n", $offenders));
});
