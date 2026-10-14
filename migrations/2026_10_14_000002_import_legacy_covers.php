<?php

use Ernestdefoe\Backdrop\LegacyImport;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Schema\Builder;

// Covers from Cover Studio or sycho/flarum-profile-cover come over on enable,
// by URL. `php flarum backdrop:import` then copies their files across.
return [
    'up' => function (Builder $schema) {
        resolve(LegacyImport::class)->import();
    },
    'down' => function (Builder $schema) {
        // The old extensions' data was only read. Forget that the import ran,
        // so reinstalling after a purge brings the covers across again.
        // Through the repository, so a settings cache (fof/redis) forgets it too.
        resolve(SettingsRepositoryInterface::class)->delete(LegacyImport::DONE);

        // Including the grants copied from Cover Studio, which no
        // permission migration of ours would otherwise remove.
        $schema->getConnection()->table('group_permission')->where('permission', 'like', 'ernestdefoe-backdrop.%')->delete();
    },
];
