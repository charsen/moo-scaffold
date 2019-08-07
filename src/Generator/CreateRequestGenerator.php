<?php
namespace Charsen\Scaffold\Generator;

/**
 * Create Request
 *
 * @author Charsen https://github.com/charsen
 */
class CreateRequestGenerator extends Generator
{
    /**
     * @param      $controller
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($controller, $force = false)
    {

    }

    /**
     * 编译模板
     *
     * @param $meta
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileStub(array $meta)
    {
        return $this->buildStub($meta, $this->getStub('request'));
    }
}
