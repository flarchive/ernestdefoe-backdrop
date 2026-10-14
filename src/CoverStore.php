<?php

namespace Ernestdefoe\Backdrop;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * The files behind covers, on the extension's own disk (public/assets/backdrop
 * unless a storage driver says otherwise).
 *
 * Each upload is stored twice: a full-width image for profiles and a small one
 * for hover cards and the member directory. A stored value is the full image's
 * file name; the small one is derived from it, so one column describes both.
 * A value containing "://" is an absolute URL brought over from another cover
 * extension, and is never deleted from here.
 */
class CoverStore
{
    public const DISK = 'ernestdefoe-backdrop';

    /** Gallery covers live here, shared by every member who chooses one. */
    public const PRESETS = 'presets/';

    private const FULL_WIDTH = 1920;
    private const SMALL_WIDTH = 960;

    // WebP cannot be taller than 16383px, and GD then writes an empty file
    // without complaint. No cover needs to be taller than this anyway.
    private const MAX_HEIGHT = 4096;

    protected Cloud $disk;

    public function __construct(Factory $filesystems, protected ImageManager $images)
    {
        $this->disk = $filesystems->disk(self::DISK);
    }

    public function store(UploadedFileInterface $file, string $folder = ''): string
    {
        return $this->storePath($file->getStream()->getMetadata('uri'), $folder);
    }

    /**
     * Store a validated image from a local path; returns the stored name.
     */
    public function storePath(string $path, string $folder = ''): string
    {
        $image = $this->images->read($path);
        $animated = $image->isAnimated();
        $name = $folder.Str::random(20).'.'.($animated ? 'gif' : 'webp');

        $written = [];

        try {
            foreach ([$name => self::FULL_WIDTH, self::small($name) => self::SMALL_WIDTH] as $target => $width) {
                $copy = clone $image;
                $copy->scaleDown(width: $width, height: self::MAX_HEIGHT);
                $encoded = (string) ($animated ? $copy->toGif() : $copy->toWebp(quality: 85));

                if ($encoded === '') {
                    throw new RuntimeException('The cover image could not be encoded.');
                }

                $this->disk->put($target, $encoded);
                $written[] = $target;
            }
        } catch (Throwable $e) {
            $this->disk->delete($written);

            throw $e;
        }

        return $name;
    }

    /**
     * Delete a member's cover files. A gallery cover is shared, so it is only
     * deleted when the gallery itself asks ($preset).
     */
    public function delete(?string $stored, bool $preset = false): void
    {
        if (! $stored || self::isExternal($stored) || (self::isPreset($stored) && ! $preset)) {
            return;
        }

        $this->disk->delete([$stored, self::small($stored)]);
    }

    public function url(?string $stored, bool $small = false): ?string
    {
        if (! $stored) {
            return null;
        }

        if (self::isExternal($stored)) {
            return $stored;
        }

        return $this->disk->url($small ? self::small($stored) : $stored);
    }

    public static function isPreset(string $stored): bool
    {
        return str_starts_with($stored, self::PRESETS);
    }

    public static function isExternal(string $stored): bool
    {
        return str_contains($stored, '://');
    }

    protected static function small(string $name): string
    {
        $dot = strrpos($name, '.');

        return substr($name, 0, $dot).'-sm'.substr($name, $dot);
    }
}
