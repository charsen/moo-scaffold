<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * FreshStorageGenerator::buildFields 回归锁(2026-06-09 修)。
 *
 * en/zh-CN 含撇号(如 Employee's Name)时,YAML 单引号串里的单引号必须双写转义(''),
 * 否则写出非法 YAML、下次 moo:fresh 的 Yaml::parseFile 抛 ParseException、整条 codegen 流水线挂。
 *
 * buildFields 是 private,且 db_schema_path / db_relative_schema_path 只在 start() 里赋值,
 * 这里反射注入临时路径 + 反射调 buildFields,断言产物 YAML 能被再次 parse(bug 版本会抛)。
 */
it('buildFields · en/zh-CN 含撇号 → 单引号双写转义,产物 YAML 可再 parse', function () {
    $gen = new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $tmp = sys_get_temp_dir() . '/freshquote_' . uniqid() . '/';
    @mkdir($tmp, 0777, true);

    foreach (['db_schema_path' => $tmp, 'db_relative_schema_path' => './fresh/'] as $prop => $val) {
        $p = new ReflectionProperty($gen, $prop);
        $p->setAccessible(true);
        $p->setValue($gen, $val);
    }

    $allFields = [
        'table_fields' => [
            'employee_name' => ['en' => "Employee's Name", 'zh-CN' => "员工's 姓名"],
        ],
    ];

    $m = new ReflectionMethod($gen, 'buildFields');
    $m->setAccessible(true);
    $m->invoke($gen, $allFields);

    $written = file_get_contents($tmp . '_fields.yaml');

    // 双写转义形态出现(bug 版本是单引号 → 串提前闭合)
    expect($written)->toContain("Employee''s Name");

    // 核心不变量:产物能被 YAML 再解析,且值无损还原(bug 版本这里抛 ParseException)
    $parsed = Yaml::parse($written);
    expect($parsed['table_fields']['employee_name']['en'])->toBe("Employee's Name");
    expect($parsed['table_fields']['employee_name']['zh-CN'])->toBe("员工's 姓名");

    (new Filesystem)->deleteDirectory(rtrim($tmp, '/'));
});
