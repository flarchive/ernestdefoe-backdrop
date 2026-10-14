<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The gallery: covers an admin uploads for members to choose from.
 *
 * Kept as one JSON setting, in order: a short list, read only when the editor
 * opens or a preset is chosen. Each file is stored once under presets/ and a
 * member who chooses it points their backdrop_cover at that same file, so
 * rendering a chosen preset costs exactly what an uploaded cover does.
 */
class Presets
{
    public const LIMIT = 24;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CoverStore $store,
        protected CoverValidator $validator,
        protected TranslatorInterface $translator,
        protected ConnectionInterface $db,
        protected Cache $cache,
    ) {
    }

    /**
     * @return list<array{id: string, file: string, x: float, y: float, zoom: float}>
     */
    public function all(bool $fresh = false): array
    {
        // Settings are cached for the request (and in Redis with fof/redis), so
        // a change made under the lock reads the row itself.
        $json = $fresh
            ? $this->db->table('settings')->where('key', Settings::PRESETS)->value('value')
            : $this->settings->get(Settings::PRESETS);
        $list = json_decode((string) $json, true);

        return is_array($list) ? array_values(array_filter($list, fn ($p) => is_array($p) && isset($p['id'], $p['file']))) : [];
    }

    /**
     * @return array{id: string, file: string, x: float, y: float, zoom: float}
     */
    public function find(mixed $id, bool $fresh = false): array
    {
        foreach ($this->all($fresh) as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }

        throw new ValidationException(['preset' => $this->translator->trans('ernestdefoe-backdrop.api.unknown_preset')]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forClient(): array
    {
        return array_map(fn (array $preset) => [
            'id' => $preset['id'],
            'url' => $this->store->url($preset['file']),
            'small' => $this->store->url($preset['file'], true),
            'x' => (float) $preset['x'],
            'y' => (float) $preset['y'],
            'zoom' => (float) $preset['zoom'],
        ], $this->all());
    }

    /**
     * @param array<string, mixed> $input
     */
    public function add(?UploadedFileInterface $file, array $input): void
    {
        if (! $file) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.no_file')]);
        }

        $this->validator->assertImageValid('cover', $file);
        $frame = Frame::fromInput($input);
        $this->assertRoom($this->all());

        // Encoding is the slow part, so it happens outside the lock; the
        // files are removed again if the gallery cannot take them.
        $stored = $this->store->store($file, CoverStore::PRESETS);

        try {
            $this->mutate(function (array $list) use ($stored, $frame) {
                $this->assertRoom($list);
                $list[] = ['id' => pathinfo($stored, PATHINFO_FILENAME), 'file' => $stored] + $frame->toArray();

                return $list;
            });
        } catch (\Throwable $e) {
            $this->store->delete($stored, true);

            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    protected function assertRoom(array $list): void
    {
        if (count($list) >= self::LIMIT) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.gallery_full', ['max' => self::LIMIT])]);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reframe(string $id, array $input): void
    {
        $frame = Frame::fromInput($input);

        $this->mutate(function (array $list) use ($id, $frame) {
            $this->find($id, true);

            return array_map(fn (array $p) => $p['id'] === $id ? array_merge($p, $frame->toArray()) : $p, $list);
        });
    }

    /**
     * Members using a deleted preset go back to the default cover.
     */
    public function remove(string $id): void
    {
        $preset = null;

        $this->mutate(function (array $list) use ($id, &$preset) {
            $preset = $this->find($id, true);
            // Inside the lock, so a member choosing this cover right now either
            // lands before this and is cleared, or after and is refused.
            $this->db->table('users')->where('backdrop_cover', $preset['file'])->update(['backdrop_cover' => null]);

            return array_values(array_filter($list, fn (array $p) => $p['id'] !== $id));
        });

        $this->store->delete($preset['file'], true);
    }

    /**
     * @param array<mixed> $ids the new order; presets not named keep their place at the end
     */
    public function reorder(array $ids): void
    {
        $rank = array_flip(array_values(array_filter($ids, 'is_string')));

        $this->mutate(function (array $list) use ($rank) {
            usort($list, fn ($a, $b) => ($rank[$a['id']] ?? PHP_INT_MAX) <=> ($rank[$b['id']] ?? PHP_INT_MAX));

            return $list;
        });
    }

    /**
     * Run a change to the gallery, or to who uses a gallery cover, one at a
     * time: read the stored list, change it, save it, all under one lock.
     */
    public function locked(callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock('ernestdefoe-backdrop.presets', 30)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw new ValidationException(['cover' => $this->translator->trans('ernestdefoe-backdrop.api.busy')]);
        }
    }

    protected function mutate(callable $change): void
    {
        $this->locked(function () use ($change) {
            $this->settings->set(Settings::PRESETS, json_encode(array_values($change($this->all(true)))));
        });
    }
}
