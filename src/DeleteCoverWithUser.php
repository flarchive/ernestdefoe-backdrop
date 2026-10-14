<?php

namespace Ernestdefoe\Backdrop;

use Flarum\User\Event\Deleted;

class DeleteCoverWithUser
{
    public function __construct(protected CoverStore $store)
    {
    }

    public function handle(Deleted $event): void
    {
        $this->store->delete($event->user->backdrop_cover);
    }
}
