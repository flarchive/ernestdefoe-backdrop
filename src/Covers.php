<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Setting, choosing, reframing and removing a member's cover. Every write is
 * checked against the matching ability here, so no endpoint can forget to.
 */
class Covers
{
    public function __construct(
        protected CoverStore $store,
        protected CoverValidator $validator,
        protected TranslatorInterface $translator,
        protected Presets $presets,
        protected FofUpload $fof,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function upload(User $actor, User $user, ?UploadedFileInterface $file, array $input): User
    {
        $actor->assertCan('uploadBackdrop', $user);

        if (! $file) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_file')]);
        }

        $this->validator->assertImageValid('cover', $file);
        $frame = Frame::fromInput($input);

        $previous = $user->backdrop_cover;

        // FoF Upload's file stays in the member's media whatever happens here,
        // like every other file they upload there.
        if ($viaFof = $this->fof->store($file, $actor, $user)) {
            $user->backdrop_cover = $viaFof['url'];
            $user->backdrop_small = $viaFof['small'];
            $user->backdrop_file_id = $viaFof['file'];
            $this->applyFrame($user, $frame);
            $user->save();
            $this->store->delete($previous);

            return $user;
        }

        $stored = $this->store->store($file);

        try {
            $user->backdrop_cover = $stored;
            $this->clearFofLink($user);
            $this->applyFrame($user, $frame);
            $user->save();
        } catch (\Throwable $e) {
            $this->store->delete($stored);

            throw $e;
        }

        $this->store->delete($previous);

        return $user;
    }

    /**
     * Point the member at a gallery cover. Without a frame of their own they
     * get the one the admin chose for it.
     *
     * @param array<string, mixed> $input
     */
    public function choose(User $actor, User $user, array $input): User
    {
        $actor->assertCan('chooseBackdrop', $user);

        // Under the gallery's lock, so the cover cannot be deleted in between.
        $preset = $this->presets->locked(function () use ($user, $input) {
            $preset = $this->presets->find($input['preset'] ?? null, true);
            $frame = Frame::fromInput(array_merge($preset, array_filter($input, fn ($v) => $v !== null && $v !== '')));

            $previous = $user->backdrop_cover;
            $user->backdrop_cover = $preset['file'];
            $this->clearFofLink($user);
            $this->applyFrame($user, $frame);
            $user->save();

            return $preset + ['previous' => $previous];
        });
        $previous = $preset['previous'];

        if ($previous !== $preset['file']) {
            $this->store->delete($previous);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reframe(User $actor, User $user, array $input): User
    {
        $actor->assertCan('editBackdrop', $user);

        if (! $user->backdrop_cover) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_cover')]);
        }

        $this->applyFrame($user, Frame::fromInput($input));
        $user->save();

        return $user;
    }

    public function remove(User $actor, User $user): User
    {
        $actor->assertCan('editBackdrop', $user);

        $previous = $user->backdrop_cover;
        $user->backdrop_cover = null;
        $this->clearFofLink($user);
        $this->applyFrame($user, new Frame());
        $user->save();

        $this->store->delete($previous);

        return $user;
    }

    // The cover no longer comes from a FoF Upload file (which stays in the
    // member's media).
    protected function clearFofLink(User $user): void
    {
        $user->backdrop_small = null;
        $user->backdrop_file_id = null;
    }

    protected function applyFrame(User $user, Frame $frame): void
    {
        $user->backdrop_x = $frame->x;
        $user->backdrop_y = $frame->y;
        $user->backdrop_zoom = $frame->zoom;
    }
}
