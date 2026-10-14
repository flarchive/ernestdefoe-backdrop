<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\AbstractImageValidator;
use Flarum\Settings\SettingsRepositoryInterface;
use Intervention\Gif\Decoder as GifDecoder;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

class CoverValidator extends AbstractImageValidator
{
    /**
     * Every frame of an animated GIF is decoded, scaled and encoded twice, so
     * core's per-image pixel cap is not enough: a few KB of GIF can hold
     * thousands of large frames. This caps the pixels across all frames.
     */
    public const MAX_ANIMATION_PIXELS = 8_000_000;

    public function getMaxSize(): int
    {
        $kb = (int) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-backdrop.max_size');

        return $kb > 0 ? $kb : 4096;
    }

    /**
     * @return list<string>
     */
    protected function getAllowedTypes(): array
    {
        return ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    }

    // Runs before core decodes the image, so a costly animation is refused
    // without ever being decoded.
    protected function assertFileMimes(UploadedFileInterface $file): void
    {
        $data = (string) $file->getStream();

        if (str_starts_with($data, 'GIF8')) {
            $size = @getimagesizefromstring($data);
            $frames = self::countFrames($data);

            if (! $size || ! $frames || $frames * $size[0] * $size[1] > self::MAX_ANIMATION_PIXELS) {
                $this->raise('dimensions');
            }
        }

        parent::assertFileMimes($file);
    }

    /**
     * Two independent counts, and the larger one wins. The decoder's own GIF
     * parser is the one that matters, since it decides what gets decoded: it
     * reads some blocks differently from the spec, so a file crafted to make
     * a spec-following walk stop early still has every frame decoded. The
     * walk stays as a second opinion for any other decoder. Neither decodes
     * pixels. Null (refused) when the file is not a GIF either can read.
     */
    public static function countFrames(string $data): ?int
    {
        try {
            $decoded = count(GifDecoder::decode($data)->getFrames());
        } catch (Throwable) {
            return null;
        }

        return max($decoded, self::gifFrames($data) ?? 0) ?: null;
    }

    /**
     * Count a GIF's frames by walking its blocks, without decoding any pixels.
     * Returns null for a file that is not a well-formed GIF.
     */
    public static function gifFrames(string $data): ?int
    {
        $length = strlen($data);

        if ($length < 13) {
            return null;
        }

        $pos = 13;
        $flags = ord($data[10]);

        if ($flags & 0x80) {
            $pos += 3 * (2 << ($flags & 0x07));
        }

        $frames = 0;

        while ($pos < $length) {
            $block = ord($data[$pos]);

            if ($block === 0x3B) {
                return $frames;
            }

            if ($block === 0x21) {
                $pos += 2;
            } elseif ($block === 0x2C) {
                $frames++;

                if ($pos + 10 > $length) {
                    return null;
                }

                $local = ord($data[$pos + 9]);
                $pos += 10 + (($local & 0x80) ? 3 * (2 << ($local & 0x07)) : 0) + 1;
            } else {
                return null;
            }

            // Data sub-blocks, ended by a zero-length one.
            while ($pos < $length && ($size = ord($data[$pos])) !== 0) {
                $pos += $size + 1;
            }

            $pos++;
        }

        // Truncated, but every frame counted so far is real.
        return $frames ?: null;
    }
}
