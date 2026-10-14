<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;

/**
 * Where a cover is framed: a focal point as x/y percentages of the image, and
 * a zoom factor. Below 1 the image is pulled back and the bands it leaves are
 * filled with a blurred copy of itself.
 */
final class Frame
{
    public const ZOOM_MIN = 0.5;
    public const ZOOM_MAX = 4.0;

    public function __construct(
        public readonly float $x = 50.0,
        public readonly float $y = 50.0,
        public readonly float $zoom = 1.0,
    ) {
    }

    /**
     * Read a frame from request input. A missing value keeps the default; a
     * present but invalid one is refused rather than silently clamped.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        return new self(
            self::number($input, 'x', 0, 100, 50.0),
            self::number($input, 'y', 0, 100, 50.0),
            self::number($input, 'zoom', self::ZOOM_MIN, self::ZOOM_MAX, 1.0),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function number(array $input, string $key, float $min, float $max, float $default): float
    {
        if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return $default;
        }

        $value = $input[$key];

        if (! is_numeric($value) || ($value = (float) $value) < $min || $value > $max) {
            throw new ValidationException([
                $key => resolve(TranslatorInterface::class)->trans('ernestdefoe-backdrop.api.invalid_'.($key === 'zoom' ? 'zoom' : 'focus')),
            ]);
        }

        return round($value, 2);
    }

    /**
     * @return array{x: float, y: float, zoom: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'zoom' => $this->zoom];
    }
}
