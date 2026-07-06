<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-17 17:04
 * @Description: Update i18n Command
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Symfony\Component\Console\Input\InputArgument;

class UpdateMultilingualCommand extends Command
{
    protected string $title = 'Update Multilingual Command';

    protected $name = 'moo:i18n';

    protected $description = 'Sync i18n language files (model enums, validation attributes, db fields)';

    protected function getArguments(): array
    {
        return [
            // plan-53:给包 schema 单独跑词条时用(词条子集进包 lang/);省略 = host 全量(原行为)
            ['schema', InputArgument::OPTIONAL, 'Only for package schema: write its word subset into the package lang/.', null],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $this->tipCallCommand('moo:i18n');

        $schema = $this->argument('schema');
        $result = (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start($schema !== null ? (string) $schema : null);

        $this->tipDone($result);
    }
}
