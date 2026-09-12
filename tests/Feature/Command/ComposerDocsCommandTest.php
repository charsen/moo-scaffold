<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/**
 * moo:composer:docs 特征测试。
 *
 * 用临时目录构造最小宿主（engine/ 下三份 manifest + 一份带 marker 的 md），覆盖：
 * 正常输出（table/matrix）、--write 只替换 marker 区间、--check 通过/过期、无 marker 拒绝。
 * 不触真实宿主、不写库。
 */
$GLOBALS['mooComposerDocsFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['mooComposerDocsFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['mooComposerDocsFixtures'] = [];
});

/**
 * 最小宿主：仓根/engine 下三份 manifest + 一份含 marker 的 PRIVATE-COMPOSER-PACKAGES.md。
 *
 * scaffold 的 test/prod 仓库 URL 刻意不同 → 输出里应标冲突；moo-feedback 不在
 * extra.moo-private-packages 里 → 应落「走 Packagist 的公开包」小表。
 */
function composerDocsHostFixture(bool $withManifests = true): string
{
    $base   = sys_get_temp_dir() . '/moo-docs-' . bin2hex(random_bytes(4));
    $engine = $base . '/engine';
    mkdir($engine, 0777, true);

    $private = [
        ['name' => 'charsen/moo-scaffold', 'repo-key' => 'scaffold', 'provider-rel' => 'src/ScaffoldProvider.php', 'publish-tag' => 'public'],
        ['name' => 'charsen/moo-system', 'repo-key' => 'system', 'provider-rel' => 'src/MooeenSystemServiceProvider.php', 'publish-tag' => null],
    ];

    $profiles = [
        'composer.json' => [
            'extra'        => ['moo-private-packages' => $private],
            'repositories' => [
                'scaffold' => ['type' => 'path', 'url' => '../../moo-scaffold', 'options' => ['symlink' => true, 'versions' => ['charsen/moo-scaffold' => '2.x-dev']]],
                'system'   => ['type' => 'path', 'url' => '../../moo-system', 'options' => ['symlink' => true, 'versions' => ['charsen/moo-system' => '1.6.x-dev']]],
            ],
            'require' => ['charsen/moo-scaffold' => '^2.1@dev', 'charsen/moo-system' => '^1.6@dev', 'charsen/moo-feedback' => '^0.1'],
        ],
        'composer.test.json' => [
            'extra'        => ['moo-private-packages' => $private],
            'repositories' => [
                'scaffold' => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-scaffold.git'],
                'system'   => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-system.git'],
            ],
            'require' => ['charsen/moo-scaffold' => 'dev-dev', 'charsen/moo-system' => 'dev-dev', 'charsen/moo-feedback' => '^0.1'],
        ],
        'composer.production.json' => [
            'extra'        => ['moo-private-packages' => $private],
            'repositories' => [
                // 与 test 不同 → 输出里必须显式标 URL 冲突
                'scaffold' => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-scaffold-mirror.git'],
                'system'   => ['type' => 'vcs', 'url' => 'git@gitee.com:charsen/moo-system.git'],
            ],
            'require' => ['charsen/moo-scaffold' => '^2.1.17', 'charsen/moo-system' => '^1.6.38', 'charsen/moo-feedback' => '^0.1'],
        ],
    ];

    if ($withManifests) {
        foreach ($profiles as $file => $data) {
            file_put_contents($engine . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    file_put_contents(
        $base . '/PRIVATE-COMPOSER-PACKAGES.md',
        "# 私有 Composer 包接入 SOP\n\n正文\n\n"
        . "<!-- BEGIN moo-manifest-table -->\n（待生成）\n<!-- END moo-manifest-table -->\n\n尾部\n",
    );

    $GLOBALS['mooComposerDocsFixtures'][] = $base;

    return $base;
}

it('moo:composer:docs table 输出 8 列私包清单表与 Packagist 公开包小表', function () {
    $root = composerDocsHostFixture();

    $this->artisan('moo:composer:docs', ['--root' => $root, '--format' => 'table'])
        ->expectsOutputToContain('| name | repo-key | provider-rel | publish-tag | 本地约束 | 测试约束 | 生产约束 | 仓库 URL |')
        ->expectsOutputToContain('| charsen/moo-scaffold | scaffold | src/ScaffoldProvider.php | public | ^2.1@dev | dev-dev | ^2.1.17 | ⚠ 冲突：test=git@gitee.com:charsen/moo-scaffold.git / prod=git@gitee.com:charsen/moo-scaffold-mirror.git |')
        ->expectsOutputToContain('### 走 Packagist 的公开包')
        ->expectsOutputToContain('| charsen/moo-feedback | ^0.1 | ^0.1 | ^0.1 | 否 |')
        ->assertExitCode(0);
});

it('moo:composer:docs matrix 输出每包三档约束对照', function () {
    $root = composerDocsHostFixture();

    $this->artisan('moo:composer:docs', ['--root' => $root, '--format' => 'matrix'])
        ->expectsOutputToContain('| name | 本地约束 | 测试约束 | 生产约束 | 是否三档一致 |')
        ->expectsOutputToContain('| charsen/moo-scaffold | ^2.1@dev | dev-dev | ^2.1.17 | ✗ |')
        ->expectsOutputToContain('| charsen/moo-system | ^1.6@dev | dev-dev | ^1.6.38 | ✗ |')
        ->assertExitCode(0);
});

it('moo:composer:docs 拒绝未知 --format', function () {
    $root = composerDocsHostFixture();

    $this->artisan('moo:composer:docs', ['--root' => $root, '--format' => 'yaml'])
        ->expectsOutputToContain('未知 --format')
        ->assertExitCode(1);
});

it('--write 只替换 marker 区间并保留区间外正文，随后 --check 通过', function () {
    $root = composerDocsHostFixture();
    $doc  = $root . '/PRIVATE-COMPOSER-PACKAGES.md';

    $this->artisan('moo:composer:docs', ['--root' => $root, '--write' => true])
        ->expectsOutputToContain('已更新')
        ->assertExitCode(0);

    $content = (string) file_get_contents($doc);
    expect($content)
        ->toContain('# 私有 Composer 包接入 SOP')
        ->toContain('尾部')
        ->toContain('### 私包清单')
        ->toContain('| charsen/moo-system |')
        ->not->toContain('（待生成）');

    // 幂等：再跑一次不重复改
    $this->artisan('moo:composer:docs', ['--root' => $root, '--write' => true])
        ->expectsOutputToContain('已是最新')
        ->assertExitCode(0);

    $this->artisan('moo:composer:docs', ['--root' => $root, '--check' => true])
        ->expectsOutputToContain('文档与三份 manifest 一致')
        ->assertExitCode(0);
});

it('--check 在文档过期时给出人可读差异摘要且非 0 退出', function () {
    $root = composerDocsHostFixture();
    $doc  = $root . '/PRIVATE-COMPOSER-PACKAGES.md';

    file_put_contents($doc, "# 过期文档\n\n<!-- BEGIN moo-manifest-table -->\n"
        . "| name | repo-key | provider-rel | publish-tag | 本地约束 | 测试约束 | 生产约束 | 仓库 URL |\n"
        . "| --- | --- | --- | --- | --- | --- | --- | --- |\n"
        . "| charsen/moo-scaffold | scaffold | src/ScaffoldProvider.php | public | ^2.1@dev | dev-dev | ^2.1.16 | git@gitee.com:charsen/moo-scaffold.git |\n"
        . "| charsen/moo-legacy | legacy | src/LegacyProvider.php | — | ^0.1@dev | dev-dev | ^0.1.0 | — |\n"
        . "<!-- END moo-manifest-table -->\n");

    $this->artisan('moo:composer:docs', ['--root' => $root, '--check' => true])
        ->expectsOutputToContain('文档已过期：新增 2 / 删除 1 / 变更 1')
        ->expectsOutputToContain('+ 新增 charsen/moo-system')
        ->expectsOutputToContain('- 删除 charsen/moo-legacy')
        ->expectsOutputToContain('~ 变更 charsen/moo-scaffold')
        ->expectsOutputToContain('生产约束: 文档=^2.1.16 → manifest=^2.1.17')
        ->assertExitCode(1);
});

it('--write 在文档没有 marker 时拒绝并且不改文件', function () {
    $root = composerDocsHostFixture();
    $doc  = $root . '/PRIVATE-COMPOSER-PACKAGES.md';

    file_put_contents($doc, "# 没有 marker 的文档\n\n正文\n");
    $before = (string) file_get_contents($doc);

    $this->artisan('moo:composer:docs', ['--root' => $root, '--write' => true])
        ->expectsOutputToContain('拒绝执行：不猜插入位置')
        ->assertExitCode(1);

    expect(file_get_contents($doc))->toBe($before);

    $this->artisan('moo:composer:docs', ['--root' => $root, '--check' => true])
        ->expectsOutputToContain('没有 marker 区间')
        ->assertExitCode(1);
});

it('三份 manifest 缺失时非 0 退出并指名缺失文件', function () {
    $root = composerDocsHostFixture(false);

    $this->artisan('moo:composer:docs', ['--root' => $root])
        ->expectsOutputToContain('缺少或无法解析 manifest')
        ->assertExitCode(1);
});

it('--bare --format=table 只出表格本体（公开包表体保留、无 ### 标题与计数）', function () {
    $root = composerDocsHostFixture();

    $this->artisan('moo:composer:docs', ['--root' => $root, '--bare' => true])
        ->expectsOutputToContain('| name | repo-key | provider-rel | publish-tag | 本地约束 | 测试约束 | 生产约束 | 仓库 URL |')
        ->expectsOutputToContain('| charsen/moo-scaffold | scaffold | src/ScaffoldProvider.php | public | ^2.1@dev | dev-dev | ^2.1.17 | ⚠ 冲突：test=git@gitee.com:charsen/moo-scaffold.git / prod=git@gitee.com:charsen/moo-scaffold-mirror.git |')
        // 公开包小表：标题不输出，但表体（表头 + 数据行）仍在
        ->expectsOutputToContain('| name | 本地约束 | 测试约束 | 生产约束 | 是否有 repository |')
        ->expectsOutputToContain('| charsen/moo-feedback | ^0.1 | ^0.1 | ^0.1 | 否 |')
        ->doesntExpectOutputToContain('###')
        ->doesntExpectOutputToContain('走 Packagist 的公开包')
        ->assertExitCode(0);
});

it('--bare --format=matrix 只出三档对照表本体（公开包表体保留、无 ### 标题）', function () {
    $root = composerDocsHostFixture();

    $this->artisan('moo:composer:docs', ['--root' => $root, '--format' => 'matrix', '--bare' => true])
        ->expectsOutputToContain('| name | 本地约束 | 测试约束 | 生产约束 | 是否三档一致 |')
        ->expectsOutputToContain('| charsen/moo-scaffold | ^2.1@dev | dev-dev | ^2.1.17 | ✗ |')
        ->expectsOutputToContain('| charsen/moo-system | ^1.6@dev | dev-dev | ^1.6.38 | ✗ |')
        ->expectsOutputToContain('| charsen/moo-feedback | ^0.1 | ^0.1 | ^0.1 | 否 |')
        ->doesntExpectOutputToContain('###')
        ->doesntExpectOutputToContain('私包三档约束对照')
        ->assertExitCode(0);
});

it('--bare --write 时 marker 区间内只写裸表，随后 --bare --check 通过', function () {
    $root = composerDocsHostFixture();
    $doc  = $root . '/PRIVATE-COMPOSER-PACKAGES.md';

    $this->artisan('moo:composer:docs', ['--root' => $root, '--bare' => true, '--write' => true])
        ->expectsOutputToContain('已更新')
        ->assertExitCode(0);

    $content = (string) file_get_contents($doc);
    expect($content)
        ->toContain("<!-- BEGIN moo-manifest-table -->\n| name | repo-key | provider-rel | publish-tag | 本地约束 | 测试约束 | 生产约束 | 仓库 URL |")
        ->toContain('| charsen/moo-feedback | ^0.1 | ^0.1 | ^0.1 | 否 |')
        ->toContain('# 私有 Composer 包接入 SOP')
        ->toContain('尾部')
        ->not->toContain('### 私包清单')
        ->not->toContain('（待生成）');

    $block = '';
    if (preg_match('/<!-- BEGIN moo-manifest-table -->(.*?)<!-- END moo-manifest-table -->/s', $content, $m) === 1) {
        $block = $m[1];
    }
    expect(trim($block))
        ->toStartWith('| name | repo-key | provider-rel | publish-tag |')
        ->not->toContain('###')
        ->not->toContain('共 ')
        ->not->toContain('走 Packagist 的公开包');

    $this->artisan('moo:composer:docs', ['--root' => $root, '--bare' => true, '--check' => true])
        ->expectsOutputToContain('文档与三份 manifest 一致')
        ->assertExitCode(0);
});
