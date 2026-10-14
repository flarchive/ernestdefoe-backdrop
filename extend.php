<?php

use Ernestdefoe\Backdrop\Access\UserPolicy;
use Ernestdefoe\Backdrop\Api\DefaultCoverController;
use Ernestdefoe\Backdrop\Api\PresetsController;
use Ernestdefoe\Backdrop\Api\UserEndpoints;
use Ernestdefoe\Backdrop\Api\UserFields;
use Ernestdefoe\Backdrop\Console\ImportCommand;
use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\DeleteCoverWithUser;
use Ernestdefoe\Backdrop\DetachDeletedFofFile;
use Ernestdefoe\Backdrop\Settings;
use Ernestdefoe\Backdrop\UploadThrottler;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\User\Event\Deleted;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Filesystem())
        ->disk(CoverStore::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/backdrop",
            'url' => $url->to('forum')->path('assets/backdrop'),
            // Readable by a web server running as another user than PHP.
            'visibility' => 'public',
        ]),

    (new Extend\Settings())
        ->default(Settings::MAX_SIZE, 4096)
        ->default(Settings::HEIGHT, 220)
        ->default(Settings::HEIGHT_MOBILE, 160)
        ->default(Settings::OVERLAY, 'gradient')
        ->default(Settings::ON_CARDS, true)
        ->default(Settings::ON_DIRECTORY, true)
        ->default(Settings::USE_FOF_UPLOAD, true),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Attribute::make('backdrop')
                ->get(fn () => resolve(Settings::class)->forForum()),
        ]),

    // Declared, so the cover columns are typed (and known to static analysis).
    (new Extend\Model(User::class))
        ->cast('backdrop_cover', 'string')
        ->cast('backdrop_x', 'float')
        ->cast('backdrop_y', 'float')
        ->cast('backdrop_zoom', 'float')
        ->cast('backdrop_small', 'string')
        ->cast('backdrop_file_id', 'int'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserFields::class)
        ->endpoints(UserEndpoints::class),

    (new Extend\Policy())
        ->modelPolicy(User::class, UserPolicy::class),

    (new Extend\Event())
        ->listen(Deleted::class, DeleteCoverWithUser::class),

    // By event name, so nothing here needs FoF Upload's classes to exist.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-upload', fn () => [
            (new Extend\Event())->listen('eloquent.deleting: FoF\\Upload\\File', DetachDeletedFofFile::class),
        ]),

    (new Extend\ThrottleApi())
        ->set('backdrop-uploads', UploadThrottler::class),

    (new Extend\Routes('api'))
        ->post('/backdrop/default', 'backdrop.default.upload', DefaultCoverController::class)
        ->patch('/backdrop/default', 'backdrop.default.frame', DefaultCoverController::class)
        ->delete('/backdrop/default', 'backdrop.default.remove', DefaultCoverController::class)
        ->get('/backdrop/presets', 'backdrop.presets.index', PresetsController::class)
        ->post('/backdrop/presets', 'backdrop.presets.create', PresetsController::class)
        ->put('/backdrop/presets', 'backdrop.presets.order', PresetsController::class)
        ->patch('/backdrop/presets/{id}', 'backdrop.presets.frame', PresetsController::class)
        ->delete('/backdrop/presets/{id}', 'backdrop.presets.remove', PresetsController::class),

    (new Extend\Console())
        ->command(ImportCommand::class),
];
