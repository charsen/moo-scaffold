<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-06-20 13:30
 * @Description: Create Api YAML Schema
 */

namespace Mooeen\Scaffold\Generator;

use Mooeen\Scaffold\Utility;
use Symfony\Component\Yaml\Yaml;

class CreateApiGenerator extends Generator
{
    private const STALE_MODE_KEEP = 'keep';

    private const STALE_MODE_DEPRECATE = 'deprecate';

    private const STALE_MODE_DELETE = 'delete';

    private const STALE_REASON = 'Route removed from current routes definition';

    private string $app;

    private string $namespace = 'Index';

    private string $apiPath;

    private string $apiRelativePath;

    private string $filesPath;

    private array $controllers = [];

    private array $publishedActions = [];

    private string $currentLoginUser = '';

    private string $generatedAt = '';

    private string $staleMode = self::STALE_MODE_DEPRECATE;

    private bool $syncNames = false;

    /** docblock name/desc 与 yaml 现值的分歧记录(start() 结尾汇报)。@var list<array{key:string,old:string,new:string,synced:bool}> */
    private array $nameDiffs = [];

    /** 当前正构建的 controller(给 nameDiffs 的 key 加前缀,避免跨 controller 同名 action 混淆) */
    private string $diffControllerName = '';

    /**
     * @throws \ReflectionException
     */
    public function start(
        string $app,
        string $namespace,
        array $routes,
        bool $force = false,
        string $staleMode = self::STALE_MODE_DEPRECATE,
        bool $syncNames = false,
    ): bool {
        $this->app              = $app;
        $this->syncNames        = $syncNames;
        $this->nameDiffs        = [];
        $this->publishedActions = [];
        $this->currentLoginUser = $this->utility->resolveCurrentLoginUser();
        $this->generatedAt      = date('Y-m-d H:i:s');
        $this->staleMode        = in_array($staleMode, [self::STALE_MODE_KEEP, self::STALE_MODE_DEPRECATE, self::STALE_MODE_DELETE], true)
            ? $staleMode
            : self::STALE_MODE_DEPRECATE;
        $this->apiPath         = $this->utility->getApiPath('schema') . $app . '/';
        $this->apiRelativePath = $this->utility->getApiPath('schema', true) . $app . '/';

        // 处理 <ROOT_PATH> 为空字符串
        $namespace       = ($namespace === '<ROOT_PATH>' || $namespace === '/') ? '' : $namespace;
        $this->filesPath = $this->apiPath . (empty($namespace) ? '' : $namespace . '/');
        $this->namespace = empty($namespace) ? 'Index' : $namespace;

        $grouped           = $this->groupRoutesByController($routes);
        $existingYamlFiles = $this->getNamespaceYamlFiles($this->filesPath);

        if ($grouped === [] && $existingYamlFiles === []) {
            $this->console()->error('当前命名空间下没有找到任何路由。');

            return false;
        }

        if ($grouped !== []) {
            $this->checkDirectory($this->filesPath);
        }

        $menuTransform   = [];
        $controllerNames = [];

        foreach ($grouped as $controllerName => $data) {
            $yamlFile        = $this->filesPath . $controllerName . '.yaml';
            $relativeYaml    = $this->buildRelativeYamlPath($controllerName);
            $reflectionClass = $this->getController($data['controller_class']);

            $pmcNames   = $this->utility->parsePMCNames($reflectionClass);
            $moduleName = $pmcNames['module']['name']['zh-CN'] ?? '';
            if ($moduleName !== '' && ! isset($menuTransform[$this->namespace])) {
                $menuTransform[$this->namespace] = $moduleName;
            }

            $controllerNames[] = $controllerName;
            $this->syncControllerFile(
                $yamlFile,
                $relativeYaml,
                $controllerName,
                $data['actions'],
                $reflectionClass,
                $force,
            );
        }

        $this->handleStaleControllerFiles($existingYamlFiles, array_keys($grouped));
        $this->updateMenusTransform($menuTransform, $this->namespace, $controllerNames);
        $this->writePublishHistory();
        $this->reportNameDiffs();

        return true;
    }

    /** docblock name 与 yaml name 分歧汇报(start 结尾)。@return list<array{key:string,old:string,new:string,synced:bool}> */
    public function getNameDiffs(): array
    {
        return $this->nameDiffs;
    }

    private function reportNameDiffs(): void
    {
        if ($this->nameDiffs === []) {
            return;
        }
        // 同 key 去重(同一 action 可能被收集多次)
        $seen  = [];
        $diffs = [];
        foreach ($this->nameDiffs as $d) {
            if (isset($seen[$d['key']])) {
                continue;
            }
            $seen[$d['key']] = true;
            $diffs[]         = $d;
        }

        if ($this->syncNames) {
            $this->console()->info('已用 docblock 同步以下接口名称(--sync-names):');
            foreach ($diffs as $d) {
                $this->console()->info("  · {$d['key']}: '{$d['old']}' → '{$d['new']}'");
            }

            return;
        }
        $this->console()->warn('以下接口的 docblock 名称与 yaml 不一致(已保留 yaml;如需同步加 --sync-names):');
        foreach ($diffs as $d) {
            $this->console()->info("  · {$d['key']}: docblock '{$d['new']}' ≠ yaml '{$d['old']}'");
        }
    }

    /**
     * 按控制器分组路由
     */
    private function groupRoutesByController(array $routes): array
    {
        $grouped = [];

        foreach ($routes as $route) {
            [$controllerClass, $actionName] = explode('@', $route['action']);
            $shortName                      = Utility::stripControllerSuffix(class_basename($controllerClass));
            $method                         = $this->normalizeMethod($route['method']);

            if (! isset($grouped[$shortName])) {
                $grouped[$shortName] = [
                    'controller_class' => $controllerClass,
                    'actions'          => [],
                ];
            }

            $grouped[$shortName]['actions'][$actionName] = [
                'method' => $method,
                'uri'    => $route['uri'],
            ];
        }

        return $grouped;
    }

    private function syncControllerFile(
        string $yamlFile,
        string $relativeYaml,
        string $controllerName,
        array $actions,
        \ReflectionClass $reflectionClass,
        bool $force,
    ): void {
        [$existingData, $duplicateActionKeys] = $this->loadExistingYamlData($yamlFile);
        $fileExists                           = $this->filesystem->isFile($yamlFile);
        $existingActions                      = is_array($existingData['actions'] ?? null) ? $existingData['actions'] : [];
        $newActionNames                       = array_values(array_diff(
            array_keys($actions),
            $this->utility->removeActionNameMethod(array_keys($existingActions))
        ));
        $staleActionKeys = $this->getStaleActionKeys($existingActions, array_keys($actions));
        $routeChanged    = $this->hasRouteSignatureChanges($existingActions, $actions);
        $restoredCount   = $this->countRestoredDeprecatedActions($existingActions, $actions);

        if ($duplicateActionKeys !== []) {
            $this->console()->warn(
                $relativeYaml . ' contains duplicate action blocks: [' . implode(', ', $duplicateActionKeys) . ']. Rewriting file.'
            );
        }

        $documentDate = $fileExists ? $this->extractDocumentDate((string) $this->filesystem->get($yamlFile)) : $this->generatedAt;
        $payload      = $this->buildControllerSchemaContent($controllerName, $actions, $reflectionClass, $existingData, $documentDate);
        $content      = $payload['content'];

        if ($fileExists) {
            $currentContent = (string) $this->filesystem->get($yamlFile);
            if (! $force && $duplicateActionKeys === [] && $currentContent === $content) {
                if ($staleActionKeys !== [] && $this->staleMode === self::STALE_MODE_KEEP) {
                    $this->console()->warn(
                        $relativeYaml . ' still contains stale actions: [' . implode(', ', $staleActionKeys) . ']'
                    );
                }
                $this->console()->unchanged($relativeYaml);

                return;
            }

            $payload = $this->buildControllerSchemaContent($controllerName, $actions, $reflectionClass, $existingData);
            $content = $payload['content'];
        }

        $put = $this->filesystem->put($yamlFile, $content);
        if (! $put) {
            $this->console()->failed($relativeYaml, 'Write failed');

            return;
        }

        if (! $fileExists) {
            $this->recordPublishedActions($controllerName, $relativeYaml, $payload['active_records'], 'create');
        } else {
            // 文件已存在时只把"新加的 action"记成 'append' 进发布历史;
            // 原地改写已有 action 不记 — re-publish 的默认副作用没信号量,
            // 否则历史里全是「覆盖 N」把真新接口淹没。
            $appendedRecords = array_values(array_filter(
                $payload['active_records'],
                static fn (array $r): bool => ! empty($r['is_new'])
            ));
            if ($appendedRecords !== []) {
                $this->recordPublishedActions($controllerName, $relativeYaml, $appendedRecords, 'append');
            }
        }

        if ($payload['deprecated_records'] !== []) {
            $this->recordPublishedActions($controllerName, $relativeYaml, $payload['deprecated_records'], 'deprecated');
        }

        if ($payload['deleted_records'] !== []) {
            $this->recordPublishedActions($controllerName, $relativeYaml, $payload['deleted_records'], 'delete');
        }

        if (! $fileExists) {
            $this->console()->created($relativeYaml);

            return;
        }

        $this->console()->updated(
            $relativeYaml,
            $this->buildSyncDetail($newActionNames, $staleActionKeys, $routeChanged, $restoredCount, $payload)
        );
    }

    private function handleStaleControllerFiles(array $existingYamlFiles, array $currentControllers): void
    {
        foreach ($existingYamlFiles as $controllerName => $yamlFile) {
            if (in_array($controllerName, $currentControllers, true)) {
                continue;
            }

            $relativeYaml = $this->buildRelativeYamlPath($controllerName);

            if ($this->staleMode === self::STALE_MODE_KEEP) {
                $this->console()->warn($relativeYaml . ' still contains a stale controller schema.');

                continue;
            }

            [$existingData]  = $this->loadExistingYamlData($yamlFile);
            $existingActions = is_array($existingData['actions'] ?? null) ? $existingData['actions'] : [];

            if ($this->staleMode === self::STALE_MODE_DELETE) {
                $this->deleteControllerFile($yamlFile, $relativeYaml, $controllerName, $existingActions);

                continue;
            }

            $documentDate   = $this->extractDocumentDate((string) $this->filesystem->get($yamlFile));
            $payload        = $this->buildControllerSchemaContent($controllerName, [], null, $existingData, $documentDate);
            $content        = $payload['content'];
            $currentContent = (string) $this->filesystem->get($yamlFile);

            if ($currentContent === $content) {
                $this->console()->unchanged($relativeYaml);

                continue;
            }

            $payload = $this->buildControllerSchemaContent($controllerName, [], null, $existingData);
            $content = $payload['content'];

            $put = $this->filesystem->put($yamlFile, $content);
            if (! $put) {
                $this->console()->failed($relativeYaml, 'Write failed');

                continue;
            }

            if ($payload['deprecated_records'] !== []) {
                $this->recordPublishedActions($controllerName, $relativeYaml, $payload['deprecated_records'], 'deprecated');
            }

            $this->console()->updated(
                $relativeYaml,
                'Deprecated ' . count($payload['deprecated_records']) . ' stale action(s)'
            );
        }
    }

    private function deleteControllerFile(
        string $yamlFile,
        string $relativeYaml,
        string $controllerName,
        array $existingActions,
    ): void {
        $deletedRecords = [];
        foreach ($existingActions as $actionKey => $actionData) {
            if (! is_array($actionData)) {
                continue;
            }
            $deletedRecords[] = $this->buildRecordFromStoredAction((string) $actionKey, $actionData);
        }

        if ($this->filesystem->delete($yamlFile)) {
            if ($deletedRecords !== []) {
                $this->recordPublishedActions($controllerName, $relativeYaml, $deletedRecords, 'delete');
            }
            $this->console()->cleaned(
                $relativeYaml,
                'Removed stale controller schema' . ($deletedRecords === [] ? '' : ' (' . count($deletedRecords) . ' action(s))')
            );

            return;
        }

        $this->console()->failed($relativeYaml, 'Delete failed');
    }

    private function buildControllerSchemaContent(
        string $controllerName,
        array $actions,
        ?\ReflectionClass $reflectionClass,
        array $existingData = [],
        ?string $documentDate = null,
    ): array {
        $this->diffControllerName  = $controllerName;
        $controllerData            = is_array($existingData['controller'] ?? null) ? $existingData['controller'] : [];
        $existingActions           = is_array($existingData['actions'] ?? null) ? $existingData['actions'] : [];
        $staleActionKeys           = $this->getStaleActionKeys($existingActions, array_keys($actions));
        $forceControllerDeprecated = $actions === [] && $this->staleMode === self::STALE_MODE_DEPRECATE && $staleActionKeys !== [];

        $names                 = $reflectionClass !== null ? $this->utility->parsePMCNames($reflectionClass) : [];
        $controllerDisplayName = trim((string) ($controllerData['name'] ?? ($names['controller']['name']['zh-CN'] ?? '')));
        $controllerCode        = $controllerData['code'] ?? '';
        $controllerDesc        = $controllerData['desc'] ?? [];

        $code   = ['###'];
        $code[] = "# {$controllerName} Api";
        $code[] = '#';
        $code[] = '# @author ' . $this->utility->getConfig('author');
        $code[] = '# @date ' . ($documentDate ?: $this->generatedAt);
        $code[] = '##';
        $code[] = 'controller:';
        $code[] = $this->getTabs(1) . 'code: ' . (is_scalar($controllerCode) ? (string) $controllerCode : '');
        // plan-40 §二 F6:走 quoteYamlString — controller name/class 来自配置 yaml / reflection,
        // 不走 SchemaLoader 校验通道,含换行 / 单引号会撕裂 yaml(40-addendum-escape-coverage-audit.md F6)
        $code[] = $this->getTabs(1) . "class: '" . $this->quoteYamlString(trim((string) ($controllerData['class'] ?? $controllerName))) . "'";
        $code[] = $this->getTabs(1) . "name: '" . $this->quoteYamlString($controllerDisplayName) . "'";
        $this->appendYamlField($code, 'desc', is_array($controllerDesc) ? $controllerDesc : [], 1);
        if ($forceControllerDeprecated) {
            $code[] = $this->getTabs(1) . 'deprecated: true';
        }
        $this->appendExtraYamlFields(
            $code,
            $controllerData,
            ['code', 'class', 'name', 'desc', 'deprecated'],
            1,
        );
        $code[] = 'actions:';

        $activeRecords     = [];
        $deprecatedRecords = [];
        $deletedRecords    = [];

        foreach ($this->resolveOrderedActiveActionNames($actions, $existingActions) as $actionName) {
            if (! isset($actions[$actionName])) {
                continue;
            }

            $attr            = $actions[$actionName];
            $activeRecords[] = $this->buildActiveAction(
                $code,
                $reflectionClass,
                $existingActions,
                $actionName,
                $attr['method'],
                $attr['uri'],
            );
        }

        foreach ($staleActionKeys as $actionKey) {
            $storedAction = $existingActions[$actionKey] ?? null;
            if (! is_array($storedAction)) {
                continue;
            }

            if ($this->staleMode === self::STALE_MODE_KEEP) {
                $this->buildStoredAction($code, $actionKey, $storedAction, false);

                continue;
            }

            if ($this->staleMode === self::STALE_MODE_DEPRECATE) {
                $alreadyDeprecated = $this->utility->isApiActionDeprecated($storedAction);
                $record            = $this->buildStoredAction($code, $actionKey, $storedAction, true);
                if (! $alreadyDeprecated) {
                    $deprecatedRecords[] = $record;
                }

                continue;
            }

            $deletedRecords[] = $this->buildRecordFromStoredAction($actionKey, $storedAction);
        }

        $code[] = '';

        return [
            'content'            => implode("\n", $code),
            'active_records'     => $activeRecords,
            'deprecated_records' => $deprecatedRecords,
            'deleted_records'    => $deletedRecords,
        ];
    }

    private function extractDocumentDate(string $content): string
    {
        if (preg_match('/^#\s*@date\s+(.+)$/m', $content, $matches) !== 1) {
            return $this->generatedAt;
        }

        $date = trim((string) ($matches[1] ?? ''));

        return $date !== '' ? $date : $this->generatedAt;
    }

    private function resolveOrderedActiveActionNames(array $actions, array $existingActions): array
    {
        $ordered = [];

        foreach (array_keys($existingActions) as $existingKey) {
            $actionName = (string) $this->utility->removeActionNameMethod((string) $existingKey);
            if (isset($actions[$actionName]) && ! in_array($actionName, $ordered, true)) {
                $ordered[] = $actionName;
            }
        }

        foreach ($this->getDefaultActions() as $actionName) {
            if (isset($actions[$actionName]) && ! in_array($actionName, $ordered, true)) {
                $ordered[] = $actionName;
            }
        }

        foreach (array_keys($actions) as $actionName) {
            if (! in_array($actionName, $ordered, true)) {
                $ordered[] = $actionName;
            }
        }

        return $ordered;
    }

    private function getStaleActionKeys(array $existingActions, array $currentActionNames): array
    {
        $stale = [];

        foreach (array_keys($existingActions) as $actionKey) {
            $actionName = (string) $this->utility->removeActionNameMethod((string) $actionKey);
            if (! in_array($actionName, $currentActionNames, true)) {
                $stale[] = (string) $actionKey;
            }
        }

        return $stale;
    }

    private function buildActiveAction(
        array &$code,
        ?\ReflectionClass $reflectionClass,
        array $existingActions,
        string $actionName,
        string $method,
        string $uri,
    ): array {
        $methodLower       = strtolower($method);
        $actionKey         = "{$actionName}_{$methodLower}";
        $existingActionKey = $this->findExistingActionKey($existingActions, $actionName, $actionKey);
        $existingAction    = $existingActionKey !== null && is_array($existingActions[$existingActionKey] ?? null)
            ? $existingActions[$existingActionKey]
            : [];
        $touchUpdatedMeta = $this->shouldTouchActiveActionMeta($existingActionKey, $existingAction, $actionKey, $method, $uri);
        $meta             = $this->buildActionMeta($existingAction, $touchUpdatedMeta);
        // name/desc 来源:docblock(第 1 行 name / 第 2 行起 desc)。已有 action 默认保留 yaml 现值(手改优先);
        //   --sync-names 时用 docblock 覆盖。无论同步与否,docblock≠yaml 都记进 nameDiffs 由 start() 结尾汇报。
        $docName         = trim((string) $this->getActionName($reflectionClass, $actionName));
        $docDesc         = $this->getActionDesc($reflectionClass, $actionName);
        $hasExistingName = array_key_exists('name', $existingAction);
        $existingName    = $hasExistingName ? trim((string) $existingAction['name']) : null;
        $diffKey         = ($this->diffControllerName !== '' ? $this->diffControllerName . '/' : '') . $actionKey;

        if (! $hasExistingName) {
            $name = $docName;                  // 新 action → docblock
        } elseif ($this->syncNames) {
            $name = $docName;                  // 显式同步 → docblock 覆盖
            if ($docName !== '' && $docName !== $existingName) {
                $this->nameDiffs[] = ['key' => $diffKey, 'old' => (string) $existingName, 'new' => $docName, 'synced' => true];
            }
        } else {
            $name = (string) $existingName;    // 默认保留 yaml
            if ($docName !== '' && $docName !== $existingName) {
                $this->nameDiffs[] = ['key' => $diffKey, 'old' => (string) $existingName, 'new' => $docName, 'synced' => false];
            }
        }

        // desc 同口径:无 yaml desc(新 action / 现状 desc:[])→ docblock;有手写 desc 则保留(sync 时 docblock 非空才覆盖)
        $existingDesc = $existingAction['desc'] ?? null;
        if ($existingDesc === null || $existingDesc === []) {
            $desc = $docDesc;
        } elseif ($this->syncNames) {
            $desc = $docDesc !== [] ? $docDesc : $existingDesc;
        } else {
            $desc = $existingDesc;
        }

        $code[] = $this->getTabs(1) . "{$actionKey}:";
        // 2026-06-11 修:name 是唯一漏走 quoteYamlString 的字符串槽。来自方法 docblock(自由文本),
        // 含半角冒号 / # / @[*-% 首字符 / 引号 → 写出非法 YAML;且每次 moo:api 从旧 yaml 回读再发射,
        // 坏一次文件就永久解析失败。跟 controller.name / meta 一致补外引号 + inner-escape。
        $code[] = $this->getTabs(2) . "name: '" . $this->quoteYamlString($name) . "'";
        $this->appendYamlField($code, 'desc', $desc);
        $this->appendYamlField($code, 'prototype', $this->getExistingActionField($existingAction, 'prototype', ''));
        if (array_key_exists('rule_action', $existingAction)) {
            $this->appendYamlField($code, 'rule_action', $existingAction['rule_action']);
        }
        $code[] = $this->getTabs(2) . "request: [{$method}, {$uri}]";
        $this->appendActionMeta($code, $meta);
        $this->appendYamlField($code, 'url_params', $this->getExistingActionField($existingAction, 'url_params', []));
        $this->appendYamlField($code, 'body_params', $this->getExistingActionField($existingAction, 'body_params', []));
        $this->appendExtraYamlFields(
            $code,
            $existingAction,
            ['name', 'desc', 'prototype', 'rule_action', 'deprecated', 'request', 'meta', 'url_params', 'body_params', 'created_at', 'updated_by', 'updated_at', 'deprecated_by', 'deprecated_at', 'deprecated_reason', 'creator', 'user'],
        );

        return [
            'action'     => $actionName,
            'action_key' => $actionKey,
            'method'     => $method,
            'uri'        => $uri,
            'name'       => $name,
            'is_new'     => $existingActionKey === null,
        ];
    }

    private function buildStoredAction(
        array &$code,
        string $actionKey,
        array $actionData,
        bool $forceDeprecated,
    ): array {
        $actionName     = (string) $this->utility->removeActionNameMethod($actionKey);
        [$method, $uri] = $this->getStoredRequest($actionData);
        $name           = trim((string) ($actionData['name'] ?? $actionName));
        $meta           = $forceDeprecated
            ? $this->buildDeprecatedActionMeta($actionData)
            : $this->utility->normalizeApiActionMeta($actionData);
        $deprecated = $forceDeprecated || $this->utility->isApiActionDeprecated($actionData);

        $code[] = $this->getTabs(1) . "{$actionKey}:";
        // 2026-06-11 修:name 是唯一漏走 quoteYamlString 的字符串槽。来自方法 docblock(自由文本),
        // 含半角冒号 / # / @[*-% 首字符 / 引号 → 写出非法 YAML;且每次 moo:api 从旧 yaml 回读再发射,
        // 坏一次文件就永久解析失败。跟 controller.name / meta 一致补外引号 + inner-escape。
        $code[] = $this->getTabs(2) . "name: '" . $this->quoteYamlString($name) . "'";
        $this->appendYamlField($code, 'desc', $actionData['desc'] ?? []);
        $this->appendYamlField($code, 'prototype', $actionData['prototype'] ?? '');
        if (array_key_exists('rule_action', $actionData)) {
            $this->appendYamlField($code, 'rule_action', $actionData['rule_action']);
        }
        if ($deprecated) {
            $code[] = $this->getTabs(2) . 'deprecated: true';
        }
        $code[] = $this->getTabs(2) . "request: [{$method}, {$uri}]";
        $this->appendActionMeta($code, $meta, $deprecated);
        $this->appendYamlField($code, 'url_params', $actionData['url_params'] ?? []);
        $this->appendYamlField($code, 'body_params', $actionData['body_params'] ?? []);
        $this->appendExtraYamlFields(
            $code,
            $actionData,
            ['name', 'desc', 'prototype', 'rule_action', 'deprecated', 'request', 'meta', 'url_params', 'body_params', 'created_at', 'updated_by', 'updated_at', 'deprecated_by', 'deprecated_at', 'deprecated_reason', 'creator', 'user'],
        );

        return [
            'action'     => $actionName,
            'action_key' => $actionKey,
            'method'     => $method,
            'uri'        => $uri,
            'name'       => $name,
        ];
    }

    private function appendActionMeta(array &$code, array $meta, bool $includeDeprecatedMeta = false): void
    {
        // plan-40 §二 escape audit:base quoteYamlString 只 inner-escape,外引号 caller 加
        $code[] = $this->getTabs(2) . 'meta:';
        $code[] = $this->getTabs(3) . "creator: '" . $this->quoteYamlString($meta['creator'] ?? '') . "'";
        $code[] = $this->getTabs(3) . "created_at: '" . $this->quoteYamlString($meta['created_at'] ?? '') . "'";

        $updatedBy = trim((string) ($meta['updated_by'] ?? ''));
        $updatedAt = trim((string) ($meta['updated_at'] ?? ''));
        $code[]    = $this->getTabs(3) . "updated_by: '" . $this->quoteYamlString($updatedBy) . "'";
        $code[]    = $this->getTabs(3) . "updated_at: '" . $this->quoteYamlString($updatedAt) . "'";

        $deprecatedBy     = trim((string) ($meta['deprecated_by'] ?? ''));
        $deprecatedAt     = trim((string) ($meta['deprecated_at'] ?? ''));
        $deprecatedReason = trim((string) ($meta['deprecated_reason'] ?? ''));
        if ($deprecatedBy !== '' || $deprecatedAt !== '' || $deprecatedReason !== '') {
            $code[] = $this->getTabs(3) . "deprecated_by: '" . $this->quoteYamlString($deprecatedBy) . "'";
            $code[] = $this->getTabs(3) . "deprecated_at: '" . $this->quoteYamlString($deprecatedAt) . "'";
            $code[] = $this->getTabs(3) . "deprecated_reason: '" . $this->quoteYamlString($deprecatedReason) . "'";
        }
    }

    private function buildActionMeta(array $existingAction, bool $touchUpdatedMeta): array
    {
        $existingMeta = $this->utility->normalizeApiActionMeta($existingAction);

        return [
            'creator'           => $existingMeta['creator']    !== '' ? $existingMeta['creator'] : $this->currentLoginUser,
            'created_at'        => $existingMeta['created_at'] !== '' ? $existingMeta['created_at'] : $this->generatedAt,
            'updated_by'        => $touchUpdatedMeta ? $this->currentLoginUser : $existingMeta['updated_by'],
            'updated_at'        => $touchUpdatedMeta ? $this->generatedAt : $existingMeta['updated_at'],
            'deprecated_by'     => '',
            'deprecated_at'     => '',
            'deprecated_reason' => '',
        ];
    }

    private function buildDeprecatedActionMeta(array $existingAction): array
    {
        $existingMeta      = $this->utility->normalizeApiActionMeta($existingAction);
        $alreadyDeprecated = $this->utility->isApiActionDeprecated($existingAction);

        return [
            'creator'    => $existingMeta['creator']    !== '' ? $existingMeta['creator'] : $this->currentLoginUser,
            'created_at' => $existingMeta['created_at'] !== '' ? $existingMeta['created_at'] : $this->generatedAt,
            'updated_by' => $alreadyDeprecated
                ? $existingMeta['updated_by']
                : $this->currentLoginUser,
            'updated_at' => $alreadyDeprecated
                ? $existingMeta['updated_at']
                : $this->generatedAt,
            'deprecated_by' => $existingMeta['deprecated_by'] !== ''
                ? $existingMeta['deprecated_by']
                : $this->currentLoginUser,
            'deprecated_at' => $existingMeta['deprecated_at'] !== ''
                ? $existingMeta['deprecated_at']
                : $this->generatedAt,
            'deprecated_reason' => $existingMeta['deprecated_reason'] !== ''
                ? $existingMeta['deprecated_reason']
                : self::STALE_REASON,
        ];
    }

    private function findExistingActionKey(array $existingActions, string $actionName, string $preferredKey): ?string
    {
        if (isset($existingActions[$preferredKey]) && is_array($existingActions[$preferredKey])) {
            return $preferredKey;
        }

        foreach ($existingActions as $existingKey => $existingAction) {
            if (! is_array($existingAction)) {
                continue;
            }

            if ($this->utility->removeActionNameMethod((string) $existingKey) === $actionName) {
                return (string) $existingKey;
            }
        }

        return null;
    }

    private function shouldTouchActiveActionMeta(
        ?string $existingActionKey,
        array $existingAction,
        string $targetActionKey,
        string $method,
        string $uri,
    ): bool {
        if ($existingAction === []) {
            return false;
        }

        [$existingMethod, $existingUri] = $this->getStoredRequest($existingAction);

        return $existingActionKey !== $targetActionKey
            || $existingMethod    !== strtoupper($method)
            || $existingUri       !== $uri
            || $this->utility->isApiActionDeprecated($existingAction);
    }

    private function hasRouteSignatureChanges(array $existingActions, array $actions): bool
    {
        foreach ($actions as $actionName => $attr) {
            $targetActionKey   = $actionName . '_' . strtolower($attr['method']);
            $existingActionKey = $this->findExistingActionKey($existingActions, $actionName, $targetActionKey);
            if ($existingActionKey === null) {
                continue;
            }

            $existingAction = $existingActions[$existingActionKey] ?? null;
            if (! is_array($existingAction)) {
                continue;
            }

            [$existingMethod, $existingUri] = $this->getStoredRequest($existingAction);
            if (
                $existingActionKey !== $targetActionKey
                || $existingMethod !== strtoupper($attr['method'])
                || $existingUri    !== $attr['uri']
            ) {
                return true;
            }
        }

        return false;
    }

    private function countRestoredDeprecatedActions(array $existingActions, array $actions): int
    {
        $count = 0;

        foreach ($actions as $actionName => $attr) {
            $targetActionKey   = $actionName . '_' . strtolower($attr['method']);
            $existingActionKey = $this->findExistingActionKey($existingActions, $actionName, $targetActionKey);
            if ($existingActionKey === null) {
                continue;
            }

            $existingAction = $existingActions[$existingActionKey] ?? null;
            if (is_array($existingAction) && $this->utility->isApiActionDeprecated($existingAction)) {
                $count++;
            }
        }

        return $count;
    }

    private function getExistingActionField(array $existingAction, string $field, mixed $default): mixed
    {
        if (! array_key_exists($field, $existingAction) || $existingAction[$field] === null) {
            return $default;
        }

        return $existingAction[$field];
    }

    private function getStoredRequest(array $actionData): array
    {
        $request = is_array($actionData['request'] ?? null) ? $actionData['request'] : [];

        return [
            strtoupper(trim((string) ($request[0] ?? 'GET'))),
            trim((string) ($request[1] ?? '')),
        ];
    }

    private function buildRecordFromStoredAction(string $actionKey, array $actionData): array
    {
        [$method, $uri] = $this->getStoredRequest($actionData);
        $actionName     = (string) $this->utility->removeActionNameMethod($actionKey);

        return [
            'action'     => $actionName,
            'action_key' => $actionKey,
            'method'     => $method,
            'uri'        => $uri,
            'name'       => trim((string) ($actionData['name'] ?? $actionName)),
        ];
    }

    private function buildSyncDetail(
        array $newActionNames,
        array $staleActionKeys,
        bool $routeChanged,
        int $restoredCount,
        array $payload,
    ): string {
        $parts = [];

        if ($newActionNames !== []) {
            $parts[] = 'Added ' . count($newActionNames) . ' action(s)';
        }

        if ($routeChanged) {
            $parts[] = 'Synced route changes';
        }

        if ($restoredCount > 0) {
            $parts[] = 'Reactivated ' . $restoredCount . ' action(s)';
        }

        if ($payload['deprecated_records'] !== []) {
            $parts[] = 'Deprecated ' . count($payload['deprecated_records']) . ' stale action(s)';
        }

        if ($payload['deleted_records'] !== []) {
            $parts[] = 'Removed ' . count($payload['deleted_records']) . ' stale action(s)';
        }

        if ($parts === [] && $staleActionKeys !== [] && $this->staleMode === self::STALE_MODE_KEEP) {
            $parts[] = 'Kept ' . count($staleActionKeys) . ' stale action(s)';
        }

        return implode('; ', $parts) ?: 'Synced file';
    }

    private function buildRelativeYamlPath(string $controllerName): string
    {
        return $this->apiRelativePath . ($this->namespace === 'Index' ? '' : $this->namespace . '/') . $controllerName . '.yaml';
    }

    private function getNamespaceYamlFiles(string $path): array
    {
        if (! $this->filesystem->isDirectory($path)) {
            return [];
        }

        $files = [];
        foreach ($this->filesystem->files($path) as $file) {
            $baseName = $file->getBasename();
            if (! str_ends_with($baseName, '.yaml') || str_starts_with($baseName, '_')) {
                continue;
            }

            $files[$file->getBasename('.yaml')] = $file->getPathname();
        }

        return $files;
    }

    private function loadExistingYamlData(string $yamlFile): array
    {
        if (! $this->filesystem->isFile($yamlFile)) {
            return [[], []];
        }

        $existingData        = $this->utility->parseYamlFile($yamlFile);
        $duplicateActionKeys = $this->getDuplicateActionKeys($yamlFile);
        if ($duplicateActionKeys !== []) {
            $existingData['actions'] = $this->getExistingActionsFromRawYaml($yamlFile);
        }

        return [$existingData, $duplicateActionKeys];
    }

    private function appendYamlField(array &$code, string $field, mixed $value, int $indentLevel = 2): void
    {
        if ($value === '') {
            $code[] = $this->getTabs($indentLevel) . "{$field}: ''";

            return;
        }

        if (is_array($value) && $value === []) {
            $code[] = $this->getTabs($indentLevel) . "{$field}: []";

            return;
        }

        $yaml = trim(Yaml::dump([$field => $value], 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        foreach (preg_split('/\R/u', $yaml) ?: [] as $line) {
            $code[] = $this->getTabs($indentLevel) . $line;
        }
    }

    private function appendExtraYamlFields(
        array &$code,
        array $data,
        array $excludedFields,
        int $indentLevel = 2,
    ): void {
        $excluded = array_flip($excludedFields);

        foreach ($data as $field => $value) {
            if (isset($excluded[(string) $field])) {
                continue;
            }

            $this->appendYamlField($code, (string) $field, $value, $indentLevel);
        }
    }

    // plan-40 §二 escape audit:子类原有 private quoteYamlString 跟父类 Generator::quoteYamlString
    // 语义不同(子类带外单引号,父类只 inner-escape)+ 同名异义 + private 覆盖 protected 报错。
    // 删私有版,所有 callsite 统一用 base helper + 外面手动 `'...'` 包(40-addendum F6/F7 修法)。

    /**
     * 获取 action 中文名称
     */
    private function getActionName(?\ReflectionClass $reflectionClass, string $actionName): string
    {
        $defaultNames = ['create' => '创建表单', 'edit' => '编辑表单'];

        if (isset($defaultNames[$actionName])) {
            return $defaultNames[$actionName];
        }

        if ($reflectionClass === null || ! $reflectionClass->hasMethod($actionName)) {
            return $actionName;
        }

        $reflectionMethod = $reflectionClass->getMethod($actionName);
        $name             = $this->utility->parseActionName($reflectionMethod);

        return $name === '' ? $actionName : $name;
    }

    /**
     * docblock 第 2 行起的描述(多行 list)。create/edit 等无 reflection 的默认 action 返回 []。
     *
     * @return list<string>
     */
    private function getActionDesc(?\ReflectionClass $reflectionClass, string $actionName): array
    {
        if ($reflectionClass === null || ! $reflectionClass->hasMethod($actionName)) {
            return [];
        }

        return $this->utility->parseActionDesc($reflectionClass->getMethod($actionName));
    }

    private function normalizeMethod(string $method): string
    {
        $methods = array_values(array_filter(array_map('trim', explode('|', strtoupper($method)))));
        if ($methods === []) {
            return 'GET';
        }

        $methods = array_values(array_unique($methods));

        // 跳过路由系统自动附带的 HEAD / OPTIONS，优先选择真正的调试方法
        $primaryMethods = array_values(array_filter(
            $methods,
            static fn (string $item): bool => ! in_array($item, ['HEAD', 'OPTIONS'], true)
        ));

        if ($primaryMethods === []) {
            $primaryMethods = $methods;
        }

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'GET'] as $candidate) {
            if (in_array($candidate, $primaryMethods, true)) {
                return $candidate;
            }
        }

        return $primaryMethods[0];
    }

    /**
     * 获取并缓存控制器的 ReflectionClass
     */
    private function getController(string $name): \ReflectionClass
    {
        if (! isset($this->controllers[$name])) {
            $this->controllers[$name] = new \ReflectionClass($name);
        }

        return $this->controllers[$name];
    }

    /**
     * 更新 _menus_transform.yaml（只追加新条目，保留用户手动排序）
     *
     * 嵌套格式：
     *   'Index':
     *       name: '根目录'
     *       controllers: [Auth, Editor, Uploader]
     */
    private function updateMenusTransform(array $newEntries, string $folderKey, array $controllerNames): void
    {
        $transformFile = $this->apiPath . '_menus_transform.yaml';
        $existing      = [];

        if ($this->filesystem->isFile($transformFile)) {
            $existing = $this->utility->normalizeMenusTransform($this->utility->parseYamlFile($transformFile));
        }

        $changed = false;

        foreach ($newEntries as $key => $name) {
            if (! isset($existing[$key])) {
                $existing[$key] = ['name' => $name, 'controllers' => $controllerNames];
                $changed        = true;
            }
        }

        if (isset($existing[$folderKey])) {
            $existingControllers = $existing[$folderKey]['controllers'] ?? [];
            $newControllers      = array_diff($controllerNames, $existingControllers);
            if ($newControllers !== []) {
                $existing[$folderKey]['controllers'] = array_merge($existingControllers, array_values($newControllers));
                $changed                             = true;
            }
        }

        if (! $changed) {
            return;
        }

        $code   = ['###'];
        $code[] = '# 转换 api 调试工具菜单';
        $code[] = '#';
        $code[] = '# 目录和控制器的顺序决定了显示排序';
        $code[] = '##';

        // plan-40 §二 F7:走 quoteYamlString — $key / $name 来自 controller config(menu key),
        // 不走 SchemaLoader 校验,含单引号会撕裂 _menus_transform.yaml(40-addendum-escape-coverage-audit.md F7)
        foreach ($existing as $key => $attr) {
            $name        = $attr['name']        ?? $key;
            $controllers = $attr['controllers'] ?? [];
            $code[]      = "'" . $this->quoteYamlString($key) . "':";
            $code[]      = $this->getTabs(1) . "name: '" . $this->quoteYamlString($name) . "'";
            $code[]      = $this->getTabs(1) . 'controllers: ' . ($controllers === [] ? '[]' : '[' . implode(', ', $controllers) . ']');
        }

        $code[] = '';
        $this->filesystem->put($transformFile, implode("\n", $code));
        $this->console()->updated('_menus_transform.yaml');
    }

    /**
     * 记录本次发布的接口
     */
    private function recordPublishedActions(string $controllerName, string $relativeYaml, array $actions, string $operation): void
    {
        foreach ($actions as $item) {
            unset($item['is_new']); // 内部判定字段,不入历史 YAML
            $this->publishedActions[] = [
                'operation'  => $operation,
                'controller' => $controllerName,
                'file'       => str_replace('\\', '/', ltrim($relativeYaml, './')),
                'debug'      => [
                    'app'        => $this->app,
                    'folder'     => $this->namespace,
                    'controller' => $controllerName,
                    'action'     => $item['action_key'] ?? '',
                ],
                ...$item,
            ];
        }
    }

    /**
     * 生成接口发布历史记录
     */
    private function writePublishHistory(): void
    {
        if ($this->publishedActions === []) {
            return;
        }

        $historyPath  = $this->utility->getApiPath('history');
        $relativePath = $this->utility->getApiPath('history', true);
        $this->checkDirectory($historyPath);

        $publishedAt     = date('Y-m-d H:i:s');
        $author          = trim((string) $this->utility->getConfig('author'));
        $controllerCount = count(array_unique(array_column($this->publishedActions, 'controller')));
        $fileName        = 'publish_' . date('Ymd_His') . '_' . substr(md5($publishedAt . $author . json_encode($this->publishedActions, JSON_UNESCAPED_UNICODE)), 8, 8) . '.yaml';
        $fullPath        = $historyPath . $fileName;

        $meta = [
            'app'              => $this->app,
            'namespace'        => $this->namespace,
            'published_at'     => $publishedAt,
            'controller_count' => $controllerCount,
            'action_count'     => count($this->publishedActions),
        ];
        if ($author !== '') {
            $meta['author'] = $author;
        }

        $data = [
            'meta'    => $meta,
            'actions' => $this->publishedActions,
        ];

        $yaml   = Yaml::dump($data, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $code   = ['###'];
        $code[] = '# Api Publish History';
        $code[] = '#';
        $code[] = '# @author ' . $author;
        $code[] = '# @date ' . $publishedAt;
        $code[] = '##';
        $code[] = trim($yaml);
        $code[] = '';

        $this->filesystem->put($fullPath, implode("\n", $code));
        $this->console()->history($relativePath . $fileName);
    }

    /**
     * 检测 actions 区块中是否存在重复 action key
     */
    private function getDuplicateActionKeys(string $yamlFile): array
    {
        if (! $this->filesystem->isFile($yamlFile)) {
            return [];
        }

        $lines     = preg_split('/\R/u', (string) $this->filesystem->get($yamlFile)) ?: [];
        $inActions = false;
        $counts    = [];

        foreach ($lines as $line) {
            if (! $inActions && trim($line) === 'actions:') {
                $inActions = true;

                continue;
            }

            if (! $inActions) {
                continue;
            }

            if ($line !== '' && preg_match('/^\S/', $line) === 1) {
                break;
            }

            if (preg_match('/^\s{4}([^\s:][^:]*)\s*:\s*$/', $line, $matches) === 1) {
                $key          = $matches[1];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
    }

    /**
     * 重复 key 会导致 Yaml::parseFile 抛异常，这里从原始文本中逐块提取 action 数据
     */
    private function getExistingActionsFromRawYaml(string $yamlFile): array
    {
        if (! $this->filesystem->isFile($yamlFile)) {
            return [];
        }

        $lines        = preg_split('/\R/u', (string) $this->filesystem->get($yamlFile)) ?: [];
        $inActions    = false;
        $currentKey   = null;
        $currentBlock = [];
        $actions      = [];

        $flush = function () use (&$actions, &$currentKey, &$currentBlock): void {
            if ($currentKey === null || $currentBlock === []) {
                return;
            }

            try {
                $parsed = Yaml::parse(implode("\n", $currentBlock)) ?: [];
            } catch (\Throwable) {
                $parsed = [];
            }

            if (is_array($parsed[$currentKey] ?? null)) {
                $actions[$currentKey] = $parsed[$currentKey];
            }

            $currentKey   = null;
            $currentBlock = [];
        };

        foreach ($lines as $line) {
            if (! $inActions && trim($line) === 'actions:') {
                $inActions = true;

                continue;
            }

            if (! $inActions) {
                continue;
            }

            if ($line !== '' && preg_match('/^\S/', $line) === 1) {
                $flush();
                break;
            }

            if (preg_match('/^\s{4}([^\s:][^:]*)\s*:\s*$/', $line, $matches) === 1) {
                $flush();
                $currentKey   = $matches[1];
                $currentBlock = [$currentKey . ':'];

                continue;
            }

            if ($currentKey !== null) {
                $currentBlock[] = str_starts_with($line, $this->getTabs(1))
                    ? substr($line, strlen($this->getTabs(1)))
                    : $line;
            }
        }

        $flush();

        return $actions;
    }

    /**
     * 获取默认的 action 用于排序输出
     */
    private function getDefaultActions(): array
    {
        return ['create', 'store', 'edit', 'update', 'index', 'trashed', 'show', 'destroy', 'destroyBatch', 'forceDestroy', 'restore'];
    }
}
