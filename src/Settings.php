<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Setting keys, and the one forum attribute they are published as.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-backdrop.';
    public const MAX_SIZE = self::PREFIX.'max_size';
    public const HEIGHT = self::PREFIX.'height';
    public const HEIGHT_MOBILE = self::PREFIX.'height_mobile';
    public const OVERLAY = self::PREFIX.'overlay';
    public const ON_CARDS = self::PREFIX.'on_cards';
    public const ON_DIRECTORY = self::PREFIX.'on_directory';
    public const DEFAULT = self::PREFIX.'default';
    public const DEFAULT_FRAME = self::PREFIX.'default_frame';
    public const PRESETS = self::PREFIX.'presets';
    public const USE_FOF_UPLOAD = self::PREFIX.'use_fof_upload';

    public const OVERLAYS = ['gradient', 'darken', 'none'];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CoverStore $store,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forForum(): array
    {
        $overlay = $this->settings->get(self::OVERLAY);

        return [
            'height' => self::clampHeight($this->settings->get(self::HEIGHT), 220),
            'heightMobile' => self::clampHeight($this->settings->get(self::HEIGHT_MOBILE), 160),
            'overlay' => in_array($overlay, self::OVERLAYS, true) ? $overlay : 'gradient',
            'onCards' => (bool) $this->settings->get(self::ON_CARDS),
            'onDirectory' => (bool) $this->settings->get(self::ON_DIRECTORY),
            'default' => $this->defaultCover(),
        ];
    }

    /**
     * @return array{url: string, small: string, x: float, y: float, zoom: float}|null
     */
    public function defaultCover(): ?array
    {
        $stored = $this->settings->get(self::DEFAULT);

        if (! $stored) {
            return null;
        }

        $frame = json_decode((string) $this->settings->get(self::DEFAULT_FRAME), true);
        $frame = Frame::fromInput(is_array($frame) ? $frame : []);

        return [
            'url' => (string) $this->store->url($stored),
            'small' => (string) $this->store->url($stored, true),
        ] + $frame->toArray();
    }

    private static function clampHeight(mixed $value, int $default): int
    {
        $value = (int) $value;

        return $value > 0 ? max(96, min(600, $value)) : $default;
    }
}
