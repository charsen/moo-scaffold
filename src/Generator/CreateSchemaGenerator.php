<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:02
 * @Description: Create Schema Generator
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;

class CreateSchemaGenerator extends Generator
{
    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false): bool
    {
        $schema_relative_file = $this->utility->getSchemaPath("{$schema_name}.yaml", true);
        $schema_file          = $this->utility->getSchemaPath("{$schema_name}.yaml");
        $schema_exists        = $this->filesystem->exists($schema_file);

        if (! $schema_exists || $force) {
            $meta = [
                'schema_name'  => $schema_name,
                'author'       => $this->utility->getConfig('author'),
                'date'         => date('Y-m-d H:i'),
                'ModuleName'   => $schema_name,
                'ModuleFolder' => $schema_name,
            ];
            $this->filesystem->put($schema_file, $this->compileStub($meta));

            if ($schema_exists) {
                $this->console()->overwritten($schema_relative_file);
            } else {
                $this->console()->created($schema_relative_file);
            }

            return true;
        }

        $this->console()->skipped($schema_relative_file);

        return false;
    }

    /**
     * @throws FileNotFoundException
     */
    protected function compileStub(array $meta): string
    {
        return $this->buildStub($meta, $this->getStub('schema'));
    }
}
