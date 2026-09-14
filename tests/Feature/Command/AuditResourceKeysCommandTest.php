<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

/**
 * moo:audit:resource-keys 特征测试。
 *
 * 用临时目录造一个最小「私包」（Models + Http/Resources），并在测试库建一张带 json 列的表，
 * 覆盖：整数键映射（带洞）判危险、字符串键/列表判安全、已声明 `$preserveKeys` 不再计危险、
 * `static` 写法单独提示、`--json` 输出与 `--fail-on-danger` 退出码。不触真实宿主、不写业务数据。
 */
$GLOBALS['mooResourceKeysFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['mooResourceKeysFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['mooResourceKeysFixtures'] = [];
    Schema::dropIfExists('audit_demo_items');
});

/**
 * 造一个最小扫描根：`<tmp>/Models/Xxx.php` + `<tmp>/Http/Resources/*.php`。
 *
 * 模型类名带随机后缀：同一个 PHP 进程里多次 require 不同临时目录的模型会「重复声明」。
 * Resource 只被**解析文本**（不 require），所以固定类名没关系。
 *
 * @param array<string, string> $resources 文件名 => 源码
 *
 * @return array{root: string, model: string}
 */
function resourceKeysFixture(array $resources, string $table = 'audit_demo_items'): array
{
    $base  = sys_get_temp_dir() . '/moo-rk-' . bin2hex(random_bytes(4));
    $model = 'AuditDemoItem' . bin2hex(random_bytes(3));
    mkdir($base . '/Models', 0777, true);
    mkdir($base . '/Http/Resources', 0777, true);

    file_put_contents($base . '/Models/' . $model . '.php', <<<PHP
<?php

namespace AuditFixture\Models;

use Illuminate\Database\Eloquent\Model;

class {$model} extends Model
{
    protected \$table = '{$table}';

    protected \$casts = [
        'meta'       => 'json',
        'plain_list' => 'json',
    ];
}
PHP);

    foreach ($resources as $name => $source) {
        file_put_contents($base . '/Http/Resources/' . $name, $source);
    }

    $fqcn = 'AuditFixture\\Models\\' . $model;
    require_once $base . '/Models/' . $model . '.php';

    $GLOBALS['mooResourceKeysFixtures'][] = $base;

    return ['root' => $base, 'model' => $fqcn];
}

/**
 * 建表并塞样本行（json 列内容由调用方给定）。
 *
 * @param array<int, array<string, mixed>> $metaValues
 */
function resourceKeysTable(string $model, array $metaValues): void
{
    Schema::create('audit_demo_items', function (Blueprint $table): void {
        $table->id();
        $table->json('meta')->nullable();
        $table->json('plain_list')->nullable();
    });

    foreach ($metaValues as $i => $meta) {
        $model::query()->insert([
            'id'         => $i + 1,
            'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'plain_list' => json_encode(['a', 'b'], JSON_UNESCAPED_UNICODE),
        ]);
    }
}

it('判定器：整数键（含带洞）危险，列表与字符串键安全', function () {
    expect(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel([1 => '正常', 2 => '停用']))->toBeTrue()
        ->and(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel([1 => 'A', 3 => 'B']))->toBeTrue()
        ->and(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel([0 => 'A', 2 => 'C']))->toBeTrue()
        // 真列表 / 字符串键映射 / 空数组都安全
        ->and(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel(['A', 'B']))->toBeFalse()
        ->and(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel(['启用' => 'A']))->toBeFalse()
        ->and(\Mooeen\Scaffold\Support\NumericKeyMapDetector::isDangerousLevel([]))->toBeFalse();

    // 递归找路径（父路径先出）
    expect(\Mooeen\Scaffold\Support\NumericKeyMapDetector::dangerPaths(['options' => [1 => '甲', 2 => '乙'], 'list' => ['x'], 'deep' => ['in' => [4 => 12]]]))
        ->toBe(['$.options', '$.deep.in']);
});

it('命令：命中整数键映射报危险，字符串键/列表报安全，并给出键路径', function () {
    $fixture = resourceKeysFixture([
        'AuditDemoItemResource.php' => <<<'PHP'
<?php

namespace AuditFixture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditDemoItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'meta'       => $this->whenHas('meta'),
            'plain_list' => $this->whenHas('plain_list'),
        ];
    }
}
PHP,
    ]);

    resourceKeysTable($fixture['model'], [
        ['options' => [1 => '正常', 2 => '停用']],   // 危险
        ['options' => ['a' => '甲', 'b' => '乙']],   // 安全（字符串键）
    ]);

    // 用 Artisan::call + Artisan::output()：`$this->artisan()` 的 PendingCommand 在 testbench 下
    // 取不到输出，而这里要解析 --json 的结果
    $code = \Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']], '--json' => true]);
    expect($code)->toBe(0);

    $rows = collect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['rows'] ?? []);

    // 有危险数据的列报 danger，并带出键路径
    $danger = $rows->firstWhere('column', 'meta');
    expect($danger['verdict'])->toBe('danger')
        ->and($danger['dangerRows'])->toBe(1)
        ->and($danger['sampled'])->toBe(2)
        ->and($danger['paths'])->toBe(['$.options']);

    // 纯列表列安全
    expect($rows->firstWhere('column', 'plain_list')['verdict'])->toBe('ok');
});

it('命令：声明了 $preserveKeys 的 Resource 不再计危险；static 写法单独提示', function () {
    $fixture = resourceKeysFixture([
        'KeepResource.php' => <<<'PHP'
<?php

namespace AuditFixture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KeepResource extends JsonResource
{
    public $preserveKeys = true;

    public function toArray(Request $request): array
    {
        return ['meta' => $this->whenHas('meta')];
    }
}
PHP,
        'StaticResource.php' => <<<'PHP'
<?php

namespace AuditFixture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaticResource extends JsonResource
{
    public static $preserveKeys = true;

    public function toArray(Request $request): array
    {
        return ['meta' => $this->whenHas('meta')];
    }
}
PHP,
    ]);

    resourceKeysTable($fixture['model'], [['options' => [1 => '正常', 2 => '停用']]]);

    \Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']], '--json' => true]);
    $rows = collect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['rows'] ?? []);

    $keep = $rows->firstWhere('resource', 'AuditFixture\Http\Resources\KeepResource');
    expect($keep['verdict'])->toBe('declared')->and($keep['staticDeclare'])->toBeFalse();

    $static = $rows->firstWhere('resource', 'AuditFixture\Http\Resources\StaticResource');
    expect($static['staticDeclare'])->toBeTrue()
        ->and($static['note'])->toContain('static');
});

it('命令：抽样失败的列计为未核验，不得被当成「无问题」（JSON 计数 + 文本告警）', function () {
    // 模型指向不存在的表 → 抽样必然失败（模拟 DB 不可达 / 表缺失）
    $fixture = resourceKeysFixture([
        'UnsamplableResource.php' => <<<'PHP'
<?php

namespace AuditFixture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnsamplableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['meta' => $this->whenHas('meta')];
    }
}
PHP,
    ], 'audit_demo_items_absent');

    $code    = \Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']], '--json' => true]);
    $payload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($payload['unverified'])->toBe(1)
        ->and($payload['rows'][0]['verdict'])->toBe('skip')
        ->and($payload['rows'][0]['sampled'])->toBe(0);

    // 文本模式：输出未核验告警，且**不能**出现「未发现危险列」这种干净结论
    \Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']]]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('未能核验')->not->toContain('未发现危险列');
});

it('命令：--fail-on-danger 命中时返回失败退出码，未命中时成功', function () {
    $fixture = resourceKeysFixture([
        'DangerResource.php' => <<<'PHP'
<?php

namespace AuditFixture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DangerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['meta' => $this->whenHas('meta')];
    }
}
PHP,
    ]);

    resourceKeysTable($fixture['model'], [['options' => [1 => '正常']]]);

    expect(\Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']], '--fail-on-danger' => true]))->toBe(1);

    // 无危险数据（同一张表换成字符串键）→ 成功
    \Illuminate\Support\Facades\DB::table('audit_demo_items')->where('id', 1)->update(['meta' => json_encode(['a' => '甲'])]);

    expect(\Illuminate\Support\Facades\Artisan::call('moo:audit:resource-keys', ['--path' => [$fixture['root']], '--fail-on-danger' => true]))->toBe(0);
});
