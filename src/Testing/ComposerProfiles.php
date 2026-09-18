<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Testing;

use JsonException;

/**
 * 三份 Composer manifest（本地 / 测试 / 生产）的只读一致性断言。
 *
 * 供 Host 部署测试复用：expect(ComposerProfiles::problems($repositoryRoot))->toBe([])。
 * 不依赖 Pest / PHPUnit，不写任何文件；$repositoryRoot 为包含 engine/ 的仓库根。
 *
 * 规则来源：根 AGENTS.md「Composer 三份 Manifest 与私包接入」。
 *  - extra.moo-private-packages 三份逐字段一致（含顺序），字段形态合法；
 *  - 本地 profile 用 sibling path（symlink + <major>.<minor>.x-dev），约束 ^<同前>@dev；
 *  - 测试 profile 用 vcs，manifest 包约束固定 dev-dev；
 *  - 生产 profile 用 vcs，manifest 包约束为已发布稳定 semver；
 *  - 测试与生产除 require 外逐字段一致，repositories 完全相同且 URL 非空；
 *  - 三份的非 charsen/* 运行时依赖基线一致。
 *
 * 允许的差异（不报问题）：公开包（require 里 charsen/* 但不在 manifest）的 @dev /
 * path 仓库，本地专用 require-dev 与 scripts 段。
 */
final class ComposerProfiles
{
    /** @var array<string, string> profile 标签 => 相对 engine/ 的文件名 */
    private const PROFILES = [
        'local'      => 'composer.json',
        'test'       => 'composer.test.json',
        'production' => 'composer.production.json',
    ];

    private const ENGINE_DIRECTORY = 'engine/';

    /**
     * @return list<string> 人类可读问题；空数组表示全部合规
     */
    public static function problems(string $repositoryRoot): array
    {
        $problems = [];
        $base     = rtrim($repositoryRoot, '/\\') . '/' . self::ENGINE_DIRECTORY;

        $profiles = [];

        foreach (self::PROFILES as $label => $file) {
            $path = $base . $file;

            if (! is_file($path)) {
                $problems[] = $file . ': 文件不存在';

                continue;
            }

            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $problems[] = $file . ': JSON 解析失败：' . $exception->getMessage();

                continue;
            }

            if (! is_array($decoded)) {
                $problems[] = $file . ': 顶层必须是 JSON 对象';

                continue;
            }

            $profiles[$label] = $decoded;
        }

        if (count($profiles) !== count(self::PROFILES)) {
            return array_values($problems);
        }

        /** @var array<string, array<string, mixed>> $requires */
        /** @var array<string, array<string, mixed>> $repositories */
        $requires     = [];
        $repositories = [];

        foreach (self::PROFILES as $label => $file) {
            $require = $profiles[$label]['require'] ?? null;
            if (! is_array($require)) {
                $problems[]       = $file . ': require 缺失或不是对象';
                $requires[$label] = [];
            } else {
                $requires[$label] = $require;
            }

            $repository = $profiles[$label]['repositories'] ?? null;
            if (! is_array($repository)) {
                $problems[]           = $file . ': repositories 缺失或不是对象';
                $repositories[$label] = [];
            } else {
                $repositories[$label] = $repository;
            }
        }

        $problems = array_merge($problems, self::manifestProblems($profiles));

        if (count($requires) === count(self::PROFILES)) {
            $problems = array_merge($problems, self::packageProblems($profiles, $requires, $repositories));
            $problems = array_merge($problems, self::baselineProblems($requires));
        }

        $problems = array_merge($problems, self::testProductionProblems($profiles));

        return array_values($problems);
    }

    /**
     * manifest 三份一致性 + 条目形态。
     *
     * 本方法（连同 packageProblems）是 `extra.moo-private-packages` 的**权威校验口径**：
     * 字段形态、name 重复、逐字段一致（含顺序）都以这里为准。另两处消费者口径**有意**更宽 ——
     * `ComposerDocsCommand::privateRows()` 只读不校验（缺 local 退 test/production，生成文档不能报错收场），
     * `AuditResourceKeysCommand::privatePackageRoots()` 读宿主单份 composer.json 且静默跳过畸形条目
     * （只读诊断命令不能因为宿主清单写歪就崩）。三处不要合并，理由见各自注释。
     *
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return list<string>
     */
    private static function manifestProblems(array $profiles): array
    {
        $problems = [];
        $valid    = [];

        foreach (self::PROFILES as $label => $file) {
            $extra    = $profiles[$label]['extra'] ?? null;
            $manifest = is_array($extra) ? ($extra['moo-private-packages'] ?? null) : null;

            if (! is_array($manifest)) {
                $problems[] = $file . ': extra.moo-private-packages 缺失或不是数组';

                continue;
            }

            $valid[$label] = $manifest;
        }

        if (count($valid) === count(self::PROFILES)) {
            $reference = $valid['production'];

            foreach (['local', 'test'] as $label) {
                if ($valid[$label] !== $reference) {
                    $problems[] = self::PROFILES[$label] . ': extra.moo-private-packages 与 '
                        . self::PROFILES['production'] . ' 不一致（含顺序）';
                }
            }
        }

        if ($valid === []) {
            return $problems;
        }

        $source   = self::PROFILES[isset($valid['production']) ? 'production' : (isset($valid['test']) ? 'test' : 'local')];
        $manifest = $valid[isset($valid['production']) ? 'production' : (isset($valid['test']) ? 'test' : 'local')];
        $seen     = [];

        foreach ($manifest as $index => $entry) {
            $position = 'extra.moo-private-packages[' . $index . ']';

            if (! is_array($entry)) {
                $problems[] = $source . ': ' . $position . ' 不是对象';

                continue;
            }

            $name    = $entry['name']     ?? null;
            $repoKey = $entry['repo-key'] ?? null;

            if (! is_string($name) || preg_match('/^charsen\/[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
                $problems[] = $source . ': ' . $position . ' 的 name 形态不合法（' . self::describe($name) . '）';
            } elseif (isset($seen[$name])) {
                $problems[] = $source . ': ' . $position . ' 的 name ' . $name . ' 重复';
            } else {
                $seen[$name] = true;
            }

            if (! is_string($repoKey) || $repoKey === '') {
                $problems[] = $source . ': ' . $position . ' 的 repo-key 形态不合法（' . self::describe($repoKey) . '）';
            }

            $providerRel = $entry['provider-rel'] ?? null;
            if ($providerRel !== null && (! is_string($providerRel) || ! str_ends_with($providerRel, '.php'))) {
                $problems[] = $source . ': ' . $position . ' 的 provider-rel 应为 null 或 .php 相对路径（'
                    . self::describe($providerRel) . '）';
            }

            $publishTag = $entry['publish-tag'] ?? null;
            if ($publishTag !== null && (! is_string($publishTag) || $publishTag === '')) {
                $problems[] = $source . ': ' . $position . ' 的 publish-tag 应为 null 或非空字符串（'
                    . self::describe($publishTag) . '）';
            }
        }

        return $problems;
    }

    /**
     * 逐 manifest 包的来源策略与约束分流。
     *
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, array<string, mixed>> $requires
     * @param array<string, array<string, mixed>> $repositories
     *
     * @return list<string>
     */
    private static function packageProblems(array $profiles, array $requires, array $repositories): array
    {
        $problems = [];
        $manifest = self::manifestOf($profiles);

        foreach ($manifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name    = $entry['name']     ?? null;
            $repoKey = $entry['repo-key'] ?? null;

            if (! is_string($name) || ! is_string($repoKey) || $repoKey === '') {
                continue;
            }

            $short = substr($name, strlen('charsen/'));

            $problems = array_merge($problems, self::localPackageProblems($requires, $repositories, $name, $repoKey, $short));
            $problems = array_merge($problems, self::testProductionPackageProblems($requires, $repositories, $name, $repoKey));
        }

        return $problems;
    }

    /**
     * @param array<string, array<string, mixed>> $requires
     * @param array<string, array<string, mixed>> $repositories
     *
     * @return list<string>
     */
    private static function localPackageProblems(array $requires, array $repositories, string $name, string $repoKey, string $short): array
    {
        $problems   = [];
        $file       = self::PROFILES['local'];
        $constraint = $requires['local'][$name] ?? null;

        if (! is_string($constraint) || $constraint === '') {
            $problems[] = $file . ': manifest 包 ' . $name . ' 缺少本地 require 约束';
        } elseif (! str_contains($constraint, '@dev')) {
            $problems[] = $file . ': ' . $name . ' 的本地约束应带 @dev，实际 ' . $constraint;
        }

        $repository = $repositories['local'][$repoKey] ?? null;

        if (! is_array($repository)) {
            $problems[] = $file . ': 私包 ' . $name . ' 缺少 repositories.' . $repoKey;

            return $problems;
        }

        $type = $repository['type'] ?? null;
        if ($type !== 'path') {
            $problems[] = $file . ': repositories.' . $repoKey . ' 的 type 应为 path，实际 ' . self::describe($type);
        }

        $url = $repository['url'] ?? null;
        if ($url !== '../../' . $short) {
            $problems[] = $file . ': repositories.' . $repoKey . ' 的 url 应为 ../../' . $short . '，实际 ' . self::describe($url);
        }

        if (($repository['options']['symlink'] ?? null) !== true) {
            $problems[] = $file . ': repositories.' . $repoKey . ' 的 options.symlink 应为 true';
        }

        $version = $repository['options']['versions'][$name] ?? null;

        if (! is_string($version) || $version === '') {
            $problems[] = $file . ': repositories.' . $repoKey . ' 缺少 options.versions.' . $name;

            return $problems;
        }

        if (! is_string($constraint) || $constraint === '') {
            return $problems;
        }

        if (! str_ends_with($version, '.x-dev')) {
            $problems[] = $file . ': repositories.' . $repoKey . ' 的 options.versions.' . $name
                . ' 应为 <major>[.<minor>].x-dev，实际 ' . $version;

            return $problems;
        }

        if (preg_match('/^\^?(\d+(?:\.\d+)*)@dev$/', $constraint, $constraintMatch) !== 1) {
            $problems[] = $file . ': ' . $name . ' 的本地约束应形如 ^<major>[.<minor>]@dev，实际 ' . $constraint;

            return $problems;
        }

        $versionBase     = substr($version, 0, -strlen('.x-dev'));
        $versionParts    = explode('.', $versionBase);
        $constraintParts = explode('.', $constraintMatch[1]);
        $matches         = count($constraintParts) <= count($versionParts)
            ? $constraintParts === array_slice($versionParts, 0, count($constraintParts))
            : $versionParts    === array_slice($constraintParts, 0, count($versionParts));

        if (! $matches) {
            $problems[] = $file . ': ' . $name . ' 的本地约束 ' . $constraint
                . ' 与 options.versions.' . $name . '=' . $version . ' 的主次版本不对应';
        }

        return $problems;
    }

    /**
     * @param array<string, array<string, mixed>> $requires
     * @param array<string, array<string, mixed>> $repositories
     *
     * @return list<string>
     */
    private static function testProductionPackageProblems(array $requires, array $repositories, string $name, string $repoKey): array
    {
        $problems = [];
        $urls     = [];

        $testConstraint = $requires['test'][$name] ?? null;

        if ($testConstraint !== 'dev-dev') {
            $problems[] = self::PROFILES['test'] . ': ' . $name . ' 的测试约束应为 dev-dev，实际 '
                . self::describe($testConstraint);
        }

        $productionConstraint = $requires['production'][$name] ?? null;

        if (! is_string($productionConstraint) || $productionConstraint === '') {
            $problems[] = self::PROFILES['production'] . ': manifest 包 ' . $name . ' 缺少生产 require 约束';
        } elseif (str_contains($productionConstraint, 'dev')
            || str_contains($productionConstraint, '@')
            || str_contains($productionConstraint, ' as ')) {
            $problems[] = self::PROFILES['production'] . ': ' . $name . ' 的生产约束应为稳定 semver，实际 '
                . $productionConstraint;
        }

        foreach (['test', 'production'] as $label) {
            $file       = self::PROFILES[$label];
            $repository = $repositories[$label][$repoKey] ?? null;

            if (! is_array($repository)) {
                $problems[] = $file . ': 私包 ' . $name . ' 缺少 repositories.' . $repoKey;

                continue;
            }

            $url = $repository['url'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                $problems[] = $file . ': repositories.' . $repoKey . ' 的 url 为空';

                continue;
            }

            $urls[$label] = $url;
        }

        if (isset($urls['test'], $urls['production']) && $urls['test'] !== $urls['production']) {
            $problems[] = self::PROFILES['test'] . ' 与 ' . self::PROFILES['production'] . ': 私包 ' . $name
                . ' 的 repositories.' . $repoKey . ' URL 不同（' . $urls['test'] . ' vs ' . $urls['production'] . '）';
        }

        return $problems;
    }

    /**
     * 测试 / 生产除 require 外逐字段一致，且 repositories 完全相同。
     *
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return list<string>
     */
    private static function testProductionProblems(array $profiles): array
    {
        $problems   = [];
        $test       = $profiles['test'];
        $production = $profiles['production'];

        $testCommon       = $test;
        $productionCommon = $production;
        unset($testCommon['require'], $productionCommon['require']);

        if ($testCommon !== $productionCommon) {
            $differences = self::changedKeys($testCommon, $productionCommon);
            $problems[]  = self::PROFILES['test'] . ' 与 ' . self::PROFILES['production']
                . ' 除 require 外存在差异的键：' . ($differences === [] ? '（顺序不同）' : implode('、', $differences));
        }

        $testRepositories       = $test['repositories']       ?? null;
        $productionRepositories = $production['repositories'] ?? null;

        if (! is_array($testRepositories) || ! is_array($productionRepositories)) {
            return $problems;
        }

        $onlyInTest       = array_diff(array_keys($testRepositories), array_keys($productionRepositories));
        $onlyInProduction = array_diff(array_keys($productionRepositories), array_keys($testRepositories));

        foreach ($onlyInTest as $key) {
            $problems[] = self::PROFILES['test'] . ': repositories.' . $key . ' 仅测试 profile 存在';
        }

        foreach ($onlyInProduction as $key) {
            $problems[] = self::PROFILES['production'] . ': repositories.' . $key . ' 仅生产 profile 存在';
        }

        foreach (array_intersect_key($testRepositories, $productionRepositories) as $key => $repository) {
            if ($repository !== $productionRepositories[$key]) {
                $problems[] = self::PROFILES['test'] . ' 与 ' . self::PROFILES['production']
                    . ': repositories.' . $key . ' 条目不一致';
            }
        }

        return $problems;
    }

    /**
     * 三份的非 charsen/* 运行时依赖基线一致。
     *
     * @param array<string, array<string, mixed>> $requires
     *
     * @return list<string>
     */
    private static function baselineProblems(array $requires): array
    {
        $problems = [];
        $local    = self::nonMooNames($requires['local']);

        foreach (['test', 'production'] as $label) {
            $other = self::nonMooNames($requires[$label]);

            if ($local !== $other) {
                $problems[] = self::PROFILES[$label] . ': 非 Moo 运行时依赖基线与 ' . self::PROFILES['local']
                    . ' 不一致' . self::listDifference($local, $other);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $require
     *
     * @return list<string>
     */
    private static function nonMooNames(array $require): array
    {
        $names = [];

        foreach (array_keys($require) as $name) {
            if (! str_starts_with((string) $name, 'charsen/')) {
                $names[] = (string) $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return list<array<string, mixed>>
     */
    private static function manifestOf(array $profiles): array
    {
        foreach (['production', 'test', 'local'] as $label) {
            $extra = $profiles[$label]['extra'] ?? null;

            if (is_array($extra) && is_array($extra['moo-private-packages'] ?? null)) {
                return array_values($extra['moo-private-packages']);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<string>
     */
    private static function changedKeys(array $a, array $b): array
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $diff = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $a) || ! array_key_exists($key, $b) || $a[$key] !== $b[$key]) {
                $diff[] = (string) $key;
            }
        }

        return $diff;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function listDifference(array $a, array $b): string
    {
        $onlyA = array_values(array_diff($a, $b));
        $onlyB = array_values(array_diff($b, $a));
        $parts = [];

        if ($onlyA !== []) {
            $parts[] = '仅本地有 ' . implode('、', $onlyA);
        }

        if ($onlyB !== []) {
            $parts[] = '仅该 profile 有 ' . implode('、', $onlyB);
        }

        return $parts === [] ? '' : '（' . implode('；', $parts) . '）';
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return $value === '' ? '(空字符串)' : $value;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}
