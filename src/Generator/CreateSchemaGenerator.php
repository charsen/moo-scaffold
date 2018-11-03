<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Schema Generator
 *
 * @author Charsen <780537@gmail.com>
 */
class CreateSchemaGenerator extends Generator
{

    /**
     * @param $schema_name
     * @param $froce
     */
    public function start($schema_name, $froce = false)
    {
        $schema_folder = $this->utility->getConfig('database.schema');
        $schema_path   = base_path() . $schema_folder . "/{$schema_name}.yaml";

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

    /**
     * @return mixed
     */
    protected function compileStub()
    {
        return $this->getStub('module-schema');
    }
}
