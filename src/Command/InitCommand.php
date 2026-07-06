<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-16 11:07
 * @Description: Init Mooeen Scaffold
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\InitGenerator;
use Symfony\Component\Console\Input\InputArgument;

class InitCommand extends Command
{
    protected string $title = 'Init Laravel Scaffold';

    protected $name = 'moo:init';

    protected $description = 'Initialize scaffold directories and write SCAFFOLD_AUTHOR to .env';

    protected function getArguments(): array
    {
        return [
            ['author', InputArgument::REQUIRED, 'Author name, written to .env as SCAFFOLD_AUTHOR and used in generated file headers. (Ex: Charsen)'],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        $author = $this->argument('author');

        $result = (new InitGenerator($this, $this->filesystem, $this->utility))
            ->start($author);

        $this->tipDone($result);
    }
}
