<?php

namespace Ernestdefoe\Backdrop\Console;

use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\LegacyImport;
use Flarum\Console\AbstractCommand;

class ImportCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('backdrop:import')
            ->setDescription('Import covers from Cover Studio or sycho/flarum-profile-cover, and copy their files onto Backdrop\'s own storage');
    }

    protected function fire(): int
    {
        $import = resolve(LegacyImport::class);
        $counts = $import->import();

        if ($counts['coverStudio'] + $counts['sycho'] > 0) {
            $this->info("Imported {$counts['coverStudio']} Cover Studio and {$counts['sycho']} Profile Cover covers.");
        }

        $this->info('Copied '.$import->localize(resolve(CoverStore::class)).' cover files onto Backdrop\'s storage.');
        $this->info('The old extension\'s data and files were left untouched.');

        return 0;
    }
}
