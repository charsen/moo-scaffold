<?php

namespace Mooeen\Scaffold\Generator;

/**
 * Create Schema Generator
 *
 * @author Charsen https://github.com/charsen
 */
class CreateSchemaGenerator extends Generator
{
    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false): bool
    {
        $schema_relative_file = $this->utility->getSchemaPatch("{$schema_name}.yaml", true);
        $schema_file          = $this->utility->getSchemaPatch("{$schema_name}.yaml");

        if (! $this->filesystem->exists($schema_file) || $force) {
            $meta = [
                'schema_name'  => $schema_name,
                'author'       => $this->utility->getConfig('author'),
                'date'         => date('Y-m-d H:i:s'),
                'ModuleName'   => $schema_name,
                'ModuleFolder' => $schema_name,
            ];
            $this->filesystem->put($schema_file, $this->compileStub($meta));

            $this->command->info("+ $schema_relative_file" . ($force ? ' (Overwrite)' : ''));

            return true;
        }

        $this->command->warn("x $schema_relative_file" . ' (Skipped)');

        return false;
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function compileStub(array $meta): string
    {
        return $this->buildStub($meta, $this->getStub('schema'));
    }
}
