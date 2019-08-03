<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Schema Generator
 *
 * @author Charsen https://github.com/charsen
 */
class CreateSchemaGenerator extends Generator
{

    /**
     * @param      $schema_name
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($schema_name, $force = false)
    {
        $schema_relative_file = $this->utility->getSchemaPatch("{$schema_name}.yaml", true);
        $schema_file          = $this->utility->getSchemaPatch("{$schema_name}.yaml");

        if ( ! $this->filesystem->exists($schema_file) || $force)
        {
            $meta = [
                'schame_name' => $schema_name,
                'author'      => $this->utility->getConfig('author'),
                'date'        => date('Y-m-d H:i:s')
            ];
            $this->filesystem->put($schema_file, $this->compileStub($meta));

            $this->command->info("+ $schema_relative_file" . ($force ? ' (Overwrited)' : ''));

            return true;
        }

        $this->command->warn("x $schema_relative_file" . ' (Skipped)');

        return false;
    }

    /**
     * @param array $meta
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function compileStub(array $meta)
    {
        return $this->buildStub($meta, $this->getStub('module-schema'));
    }
}
