<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Each upload is decoded and re-encoded twice, so a member gets six a minute.
 * Admins are not limited.
 */
class UploadThrottler
{
    public const PER_MINUTE = 6;

    public function __construct(protected Repository $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'users.backdrop.upload') {
            return null;
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest() || $actor->isAdmin()) {
            return null;
        }

        $key = 'ernestdefoe-backdrop.uploads.'.$actor->id;
        $this->cache->add($key, 0, 60);

        return $this->cache->increment($key) > self::PER_MINUTE ? true : null;
    }
}
