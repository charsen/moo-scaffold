<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Utility;
use SplFileInfo;

class ApiSchemaService
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Utility $utility,
    ) {}

    public function summarizeApps(array $apps): array
    {
        $stats = $this->getAppStats($apps);

        return [
            'api_count' => array_sum(array_column($stats, 'api_count')),
            'apps'      => $stats,
        ];
    }

    public function getAppStats(array $apps): array
    {
        $data     = [];
        $basePath = rtrim($this->utility->getApiPath('schema'), '/') . '/';

        foreach ($apps as $app => $name) {
            $stats = [
                'name'             => $name,
                'module_count'     => 0,
                'controller_count' => 0,
                'api_count'        => 0,
            ];
            $modules = [];
            $appPath = $basePath . $app;

            if (! $this->filesystem->isDirectory($appPath)) {
                $data[$app] = $stats;

                continue;
            }

            foreach ($this->filesystem->allFiles($appPath) as $file) {
                if (! $this->isSchemaFile($file)) {
                    continue;
                }

                $folderName           = $this->resolveFolderName($file);
                $modules[$folderName] = true;
                $yamlData             = $this->utility->parseYamlFile($file->getPathname());

                if ($yamlData === []) {
                    continue;
                }

                $controller = is_array($yamlData['controller'] ?? null) ? $yamlData['controller'] : [];
                $actions    = is_array($yamlData['actions'] ?? null) ? $yamlData['actions'] : [];

                if ($controller !== []) {
                    $stats['controller_count']++;
                }

                foreach ($actions as $attr) {
                    // deprecated(moo:api --stale=deprecate 标记的死接口)不算活接口
                    if (is_array($attr) && ! empty($attr['deprecated'])) {
                        continue;
                    }
                    $stats['api_count']++;
                }
            }

            $stats['module_count'] = count($modules);
            $data[$app]            = $stats;
        }

        return $data;
    }

    private function isSchemaFile(SplFileInfo $file): bool
    {
        $baseName = $file->getBasename();

        return str_ends_with($baseName, '.yaml') && ! str_starts_with($baseName, '_');
    }

    private function resolveFolderName(SplFileInfo $file): string
    {
        return empty($file->getRelativePath()) ? 'Index' : $file->getRelativePath();
    }
}
