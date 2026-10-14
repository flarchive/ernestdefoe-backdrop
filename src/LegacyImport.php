<?php

namespace Ernestdefoe\Backdrop;

use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Throwable;

/**
 * Brings covers over from tryhackx/flarum-cover-studio and
 * sycho/flarum-profile-cover. Never destructive: their tables, columns and
 * files are only read, and a member who already has a Backdrop cover is
 * skipped.
 *
 * Two steps, because Flarum runs an extension's migrations before its
 * extenders boot, so Backdrop's disk does not exist yet when it is enabled:
 *
 * 1. import() — run by the enable migration. Covers come over as the URLs they
 *    are already served from, so every one shows straight away. It runs once:
 *    the first time legacy data is found, never again.
 * 2. localize() — run by `php flarum backdrop:import`. Covers whose file is on
 *    this server are copied onto Backdrop's disk, after which nothing depends
 *    on the old extension. Remote files (S3 and the like) stay as URLs, and
 *    while FoF Upload is in use its files stay with it.
 */
class LegacyImport
{
    public const DONE = Settings::PREFIX.'legacy_imported';

    /** The length of users.backdrop_cover. */
    public const MAX_URL = 1024;

    protected Builder $schema;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths,
    ) {
        $this->schema = $db->getSchemaBuilder();
    }

    /**
     * @return array{coverStudio: int, sycho: int}
     */
    public function import(): array
    {
        $counts = ['coverStudio' => 0, 'sycho' => 0];
        $sources = $this->schema->hasTable('cover_studio_user_data') || $this->schema->hasColumn('users', 'cover');

        // Once only. After that, a member with no cover may have removed it, or
        // had their gallery cover deleted, and must not get the old one back.
        if (! $sources || $this->stored(self::DONE) !== null) {
            return $counts;
        }

        $counts = [
            'coverStudio' => $this->fromCoverStudio(),
            'sycho' => $this->fromSycho(),
        ];

        $this->settings->set(self::DONE, '1');

        return $counts;
    }

    /**
     * Copy URL-referenced covers that live on this server onto Backdrop's disk.
     */
    public function localize(CoverStore $store): int
    {
        $count = 0;
        $query = $this->db->table('users')
            ->select(['id', 'backdrop_cover'])
            ->where('backdrop_cover', 'like', 'http%://%');

        // While FoF Upload is in use, a cover that is one of its files stays
        // there, in the member's My Media.
        if (resolve(FofUpload::class)->enabled()) {
            $query->whereNull('backdrop_file_id');
        }

        $query
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($store, &$count) {
                foreach ($rows as $row) {
                    if (! ($stored = $this->copy($store, $row->backdrop_cover))) {
                        continue;
                    }

                    $updated = $this->db->table('users')
                        ->where('id', $row->id)
                        ->where('backdrop_cover', $row->backdrop_cover)
                        ->update(['backdrop_cover' => $stored, 'backdrop_small' => null, 'backdrop_file_id' => null]);

                    $updated ? $count++ : $store->delete($stored);
                }
            });

        $default = $this->settings->get(Settings::DEFAULT);

        if ($default && CoverStore::isExternal($default) && ($stored = $this->copy($store, $default))) {
            $this->settings->set(Settings::DEFAULT, $stored);
        }

        return $count;
    }

    protected function fromCoverStudio(): int
    {
        if (! $this->schema->hasTable('cover_studio_user_data')) {
            return 0;
        }

        $count = 0;

        $this->db->table('cover_studio_user_data')
            ->whereNotNull('cover_file_id')
            ->whereNotNull('cover_url')
            ->orderBy('user_id')
            ->chunk(200, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    // Cover Studio's covers are FoF Upload files: keep the link
                    // and its thumbnail, so My Media stays in charge of them.
                    $count += (int) $this->give((int) $row->user_id, (string) $row->cover_url, [
                        'x' => $row->cover_focus_x,
                        'y' => $row->cover_focus_y,
                        'zoom' => $row->cover_zoom,
                    ], [
                        'backdrop_small' => self::isHttp((string) ($row->cover_thumb_url ?? '')) && strlen((string) $row->cover_thumb_url) <= self::MAX_URL ? $row->cover_thumb_url : null,
                        'backdrop_file_id' => $row->cover_file_id,
                    ]);
                }
            });

        $this->copySettings();
        $this->copyPermission('tryhackx-cover-studio.setCover');

        return $count;
    }

    protected function fromSycho(): int
    {
        if (! $this->schema->hasColumn('users', 'cover')) {
            return 0;
        }

        $count = 0;
        $base = null;

        $this->db->table('users')
            ->select(['id', 'cover'])
            ->whereNotNull('cover')
            ->where('cover', '!=', '')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$base, &$count) {
                $base ??= $this->forumBase().'assets/covers/';

                foreach ($rows as $row) {
                    $count += (int) $this->give((int) $row->id, $base.rawurlencode(basename((string) $row->cover)), []);
                }
            });

        return $count;
    }

    /**
     * @param array<string, mixed> $frame
     * @param array<string, mixed> $extra
     */
    protected function give(int $userId, string $url, array $frame, array $extra = []): bool
    {
        if (! self::isHttp($url) || strlen($url) > self::MAX_URL) {
            return false;
        }

        try {
            $frame = Frame::fromInput($frame);
        } catch (Throwable) {
            $frame = new Frame();
        }

        return (bool) $this->db->table('users')
            ->where('id', $userId)
            ->whereNull('backdrop_cover')
            ->update([
                'backdrop_cover' => $url,
                'backdrop_x' => $frame->x,
                'backdrop_y' => $frame->y,
                'backdrop_zoom' => $frame->zoom,
            ] + $extra);
    }

    /**
     * Store the file behind a URL on this forum, if it is on this server and is
     * an image of sane dimensions. Returns the stored name, or null.
     */
    protected function copy(CoverStore $store, string $url): ?string
    {
        $base = $this->forumBase();

        if (! str_starts_with($url, $base)) {
            return null;
        }

        $relative = rawurldecode((string) parse_url(substr($url, strlen($base)), PHP_URL_PATH));

        // realpath() throws on a NUL byte rather than returning false.
        if (str_contains($relative, "\0")) {
            return null;
        }

        $public = realpath($this->paths->public);
        $file = realpath($this->paths->public.'/'.$relative);

        if (! $public || ! $file || ! str_starts_with($file, $public.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        $size = @getimagesize($file);

        if (! $size || $size[0] * $size[1] > 24_000_000) {
            return null;
        }

        try {
            return $store->storePath($file);
        } catch (Throwable) {
            return null;
        }
    }

    protected function copySettings(): void
    {
        $map = [
            'cover_height' => Settings::HEIGHT,
            'cover_height_mobile' => Settings::HEIGHT_MOBILE,
            'overlay' => Settings::OVERLAY,
            'show_on_popover' => Settings::ON_CARDS,
            'show_on_directory' => Settings::ON_DIRECTORY,
            'max_size' => Settings::MAX_SIZE,
        ];

        foreach ($map as $theirs => $ours) {
            $value = $this->stored('tryhackx-cover-studio.'.$theirs);

            if ($value !== null && $this->stored($ours) === null) {
                $this->settings->set($ours, $value);
            }
        }

        $default = (string) $this->stored('tryhackx-cover-studio.default_cover_url');

        if (self::isHttp($default) && $this->stored(Settings::DEFAULT) === null) {
            $this->settings->set(Settings::DEFAULT, $default);
            $this->settings->set(Settings::DEFAULT_FRAME, json_encode([
                'x' => (float) ($this->stored('tryhackx-cover-studio.default_cover_focus_x') ?? 50),
                'y' => (float) ($this->stored('tryhackx-cover-studio.default_cover_focus_y') ?? 50),
                'zoom' => (float) ($this->stored('tryhackx-cover-studio.default_cover_zoom') ?? 1),
            ]));
        }
    }

    /**
     * Groups that could set a cover before still can.
     */
    protected function copyPermission(string $theirs): void
    {
        foreach ($this->db->table('group_permission')->where('permission', $theirs)->pluck('group_id') as $group) {
            $this->db->table('group_permission')->insertOrIgnore([
                'group_id' => $group,
                'permission' => 'ernestdefoe-backdrop.set',
            ]);
        }
    }

    /**
     * What is actually saved, ignoring registered defaults, which the settings
     * repository would otherwise report as if an admin had chosen them.
     */
    protected function stored(string $key): ?string
    {
        $value = $this->db->table('settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Resolved only when needed: building the URL generator builds the route
     * table, and inside the enable migration that happens before Backdrop's
     * own routes are registered, leaving that boot without them.
     */
    protected function forumBase(): string
    {
        return rtrim(resolve(UrlGenerator::class)->to('forum')->base(), '/').'/';
    }

    protected static function isHttp(string $url): bool
    {
        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
