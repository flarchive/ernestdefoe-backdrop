<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use FoF\Upload\Commands\Upload;
use FoF\Upload\File;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Support\Collection;
use Psr\Http\Message\UploadedFileInterface;

/**
 * When FoF Upload is enabled, a member's cover goes through it like any other
 * image they upload: its type checks, resizing, watermark, storage adapter
 * (local, S3, …) and WebP thumbnails, and the file lands in the member's own
 * My Media. Without it, or when it will not take the image, Backdrop stores
 * the cover itself.
 */
class FofUpload
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected SettingsRepositoryInterface $settings,
        protected Bus $bus,
    ) {
    }

    public function enabled(): bool
    {
        return $this->extensions->isEnabled('fof-upload')
            && class_exists(Upload::class)
            && (bool) $this->settings->get(Settings::USE_FOF_UPLOAD);
    }

    /**
     * Store the cover through FoF Upload, owned by the member whose cover it
     * is. Null when FoF Upload is off, the actor may not use it, or it is not
     * configured to accept the image; the caller then stores it itself.
     *
     * @return array{url: string, small: string, file: int}|null
     */
    public function store(UploadedFileInterface $upload, User $actor, User $owner): ?array
    {
        if (! $this->enabled() || ! $actor->can('fof-upload.upload')) {
            return null;
        }

        try {
            /** @var Collection<int, File> $files */
            $files = $this->bus->dispatch(new Upload(collect([$upload]), $actor));
        } catch (ValidationException|PermissionDeniedException) {
            return null;
        }

        $file = $files->first();

        if (! $file instanceof File) {
            return null;
        }

        // A moderator setting someone's cover: it belongs in their media.
        if ($file->actor_id !== $owner->id) {
            $file->actor_id = $owner->id;
            $file->save();
        }

        return [
            'url' => (string) $file->url,
            'small' => (string) ($file->thumbnail_url ?: $file->url),
            'file' => (int) $file->id,
        ];
    }
}
