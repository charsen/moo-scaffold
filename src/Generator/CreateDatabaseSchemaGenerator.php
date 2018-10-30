<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Database Schema Generator
 *
 * @author   Charsen <780537@gmail.com>
 */
class CreateDatabaseSchemaGenerator extends Generator
{

    public function start($file_name, $froce = false)
    {
        $schema_folder = $this->utility->getConfig('database.schema');
        $schema_path   = base_path() . $schema_folder . "/{$file_name}.yaml";

        if (!$this->filesystem->exists($schema_path))
        {
            $this->filesystem->put($schema_path, $this->compileStub());

            return $this->command->info("+ $schema_path");
        }

        if ($froce)
        {
            $this->filesystem->put($schema_path, $this->compileStub());

            return $this->command->info("+ $schema_path" . ' (Overwrited)');
        }

        return $this->command->warn("x $schema_path" . ' (Skipped)');
    }

    protected function compileStub()
    {
        $file = $this->getStubPath() . '/schema-database.stub';
        $stub = $this->filesystem->get($file);

        //$this->buildStub($this->getMeta(), $stub);

        return $stub;
    }
}
