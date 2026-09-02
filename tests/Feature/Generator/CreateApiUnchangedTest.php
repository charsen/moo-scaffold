<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @module_name {zh-CN: 附件 | en: Attachment}
 * @controller_name {zh-CN: 附件管理 | en: Attachment}
 */
class ApiUnchangedAttachmentController
{
    /**
     * 附件列表
     */
    public function index(): void {}

    /**
     * 附件详情
     */
    public function show(): void {}
}

function apiUnchangedGenerator(
    string $generatedAt = '2026-09-02 09:33:53',
    bool $syncNames = false,
): CreateApiGenerator {
    $generator = new CreateApiGenerator(
        new BufferedOutput,
        app(Filesystem::class),
        app(Utility::class),
    );

    $state = [
        'app'              => 'admin',
        'namespace'        => 'Attachment',
        'generatedAt'      => $generatedAt,
        'currentLoginUser' => 'charsen',
        'syncNames'        => $syncNames,
    ];
    foreach ($state as $property => $value) {
        (new ReflectionProperty($generator, $property))->setValue($generator, $value);
    }

    return $generator;
}

function apiUnchangedSync(
    CreateApiGenerator $generator,
    string $yamlFile,
    ?array $actions = null,
    bool $force = false,
): void {
    (new ReflectionMethod($generator, 'syncControllerFile'))->invoke(
        $generator,
        $yamlFile,
        './scaffold/api/admin/Attachment/Attachment.yaml',
        'Attachment',
        $actions ?? ['index' => ['method' => 'GET', 'uri' => 'api/admin/attachments']],
        new ReflectionClass(ApiUnchangedAttachmentController::class),
        $force,
    );
}

beforeEach(function () {
    $this->apiDir = sys_get_temp_dir() . '/moo-scaffold-api-unchanged-' . bin2hex(random_bytes(6));
    mkdir($this->apiDir, 0o777, true);
    $this->yamlFile = $this->apiDir . '/Attachment.yaml';
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->apiDir);
});

/**
 * 先按当前排版生成一份基准，再退回旧生成器的排版（空 code 带尾空格）并把 @date 改老，
 * 模拟仓库里那些由历史版本写出的 yaml。
 */
function apiUnchangedLegacyFile(string $yamlFile): string
{
    apiUnchangedSync(apiUnchangedGenerator(), $yamlFile);
    $generated = (string) file_get_contents($yamlFile);

    expect($generated)->toContain("    code:\n");

    $legacy = str_replace(
        ["    code:\n", '# @date 2026-09-02 09:33:53'],
        ["    code: \n", '# @date 2026-07-17 15:21:33'],
        $generated,
    );
    file_put_contents($yamlFile, $legacy);

    return $legacy;
}

it('leaves a semantically identical yaml byte-for-byte untouched', function () {
    $legacy = apiUnchangedLegacyFile($this->yamlFile);

    apiUnchangedSync(apiUnchangedGenerator(), $this->yamlFile);

    // 排版差异（尾空格）与注释里的 @date 都不算变化：不重写、不刷时间。
    expect(file_get_contents($this->yamlFile))->toBe($legacy);
});

it('still rewrites an equivalent yaml when force is requested', function () {
    $legacy = apiUnchangedLegacyFile($this->yamlFile);

    apiUnchangedSync(apiUnchangedGenerator(), $this->yamlFile, force: true);

    expect(file_get_contents($this->yamlFile))->not->toBe($legacy)
        ->toContain("    code:\n")
        ->toContain('# @date 2026-09-02 09:33:53');
});

it('rewrites and refreshes the document date when an action is added', function () {
    $legacy = apiUnchangedLegacyFile($this->yamlFile);

    apiUnchangedSync(apiUnchangedGenerator('2026-09-03 10:00:00'), $this->yamlFile, [
        'index' => ['method' => 'GET', 'uri' => 'api/admin/attachments'],
        'show'  => ['method' => 'GET', 'uri' => 'api/admin/attachments/{id}'],
    ]);

    expect(file_get_contents($this->yamlFile))->not->toBe($legacy)
        ->toContain('show_get:')
        ->toContain('# @date 2026-09-03 10:00:00');
});

it('rewrites when sync-names changes an action name', function () {
    $legacy = apiUnchangedLegacyFile($this->yamlFile);
    file_put_contents($this->yamlFile, str_replace("name: '附件列表'", "name: '旧动作名称'", $legacy));

    apiUnchangedSync(apiUnchangedGenerator('2026-09-03 10:00:00', true), $this->yamlFile);

    expect(file_get_contents($this->yamlFile))->toContain("name: '附件列表'")
        ->toContain('# @date 2026-09-03 10:00:00');
});

it('rewrites a yaml that cannot be parsed instead of skipping it', function () {
    file_put_contents($this->yamlFile, "###\ncontroller:\n  - broken\n\tmapping: [\n");

    apiUnchangedSync(apiUnchangedGenerator(), $this->yamlFile);

    expect(file_get_contents($this->yamlFile))->toContain('index_get:')
        ->toContain('# @date 2026-09-02 09:33:53');
});
