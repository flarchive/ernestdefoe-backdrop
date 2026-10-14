<?php

namespace Ernestdefoe\Backdrop\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Your own cover follows the extension's two permissions; anyone else's is a
 * moderation action and needs core's "edit users", like avatars.
 */
class UserPolicy extends AbstractPolicy
{
    public function uploadBackdrop(User $actor, User $user): ?string
    {
        return $this->decide($actor, $user, 'ernestdefoe-backdrop.set');
    }

    public function chooseBackdrop(User $actor, User $user): ?string
    {
        return $this->decide($actor, $user, 'ernestdefoe-backdrop.choose');
    }

    /**
     * Reframing or removing a cover you already have needs neither
     * permission: losing the right to set one must not trap you with it.
     */
    public function editBackdrop(User $actor, User $user): ?string
    {
        return $this->decide($actor, $user, null);
    }

    protected function decide(User $actor, User $user, ?string $permission): string
    {
        if ($actor->isGuest()) {
            return $this->deny();
        }

        if ($actor->id !== $user->id) {
            return $actor->can('edit', $user) ? $this->allow() : $this->deny();
        }

        return $permission === null || $actor->hasPermission($permission) ? $this->allow() : $this->deny();
    }
}
