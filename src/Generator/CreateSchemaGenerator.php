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
     * @param $force
     */
    public function start($schema_name, $force = false)
    {
        $schema_relative_file = $this->utility->getSchema("{$schema_name}.yaml", true);
        $schema_file          = $this->utility->getSchema("{$schema_name}.yaml");
        
        if (!$this->filesystem->exists($schema_file) || $force)
        {
            $this->filesystem->put($schema_file, $this->compileStub());

            return $this->command->info("+ $schema_relative_file" . ($force ? ' (Overwrited)' : ''));
        }

        return $this->command->warn("x $schema_relative_file" . ' (Skipped)');
    }

    /**
     * @return mixed
     */
    protected function compileStub()
    {
        return $this->getStub('module-schema');
    }
}
