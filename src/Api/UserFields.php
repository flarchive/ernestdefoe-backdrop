<?php

namespace Ernestdefoe\Backdrop\Api;

use Ernestdefoe\Backdrop\CoverStore;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

class UserFields
{
    /**
     * Cover data lives in columns on `users`, like core's avatar_url, so it
     * arrives with every user row that is already being loaded: no relation,
     * no eager loading, nothing added to anyone else's queries.
     *
     * @return array<\Tobyz\JsonApiServer\Schema\Field\Field>
     */
    public function __invoke(): array
    {
        // Only you, or a moderator, can ever hold these abilities; for every
        // other user in a list the policy is not asked at all.
        $mayHaveAbilities = function (User $user, Context $context): bool {
            $actor = $context->getActor();

            return ! $actor->isGuest() && ($actor->id === $user->id || $actor->hasPermission('user.edit'));
        };

        return [
            Schema\Attribute::make('backdrop')
                ->get(function (User $user) {
                    if (! $user->backdrop_cover) {
                        return null;
                    }

                    $store = resolve(CoverStore::class);

                    return [
                        'url' => $store->url($user->backdrop_cover),
                        'small' => $user->backdrop_small ?: $store->url($user->backdrop_cover, true),
                        'x' => (float) $user->backdrop_x,
                        'y' => (float) $user->backdrop_y,
                        'zoom' => (float) $user->backdrop_zoom,
                        'preset' => CoverStore::isPreset($user->backdrop_cover) ? pathinfo($user->backdrop_cover, PATHINFO_FILENAME) : null,
                    ];
                }),

            Schema\Boolean::make('canUploadBackdrop')
                ->visible($mayHaveAbilities)
                ->get(fn (User $user, Context $context) => $context->getActor()->can('uploadBackdrop', $user)),

            Schema\Boolean::make('canChooseBackdrop')
                ->visible($mayHaveAbilities)
                ->get(fn (User $user, Context $context) => $context->getActor()->can('chooseBackdrop', $user)),
        ];
    }
}
