<?php

namespace Ernestdefoe\Backdrop\Tests\integration\api;

use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\CoverValidator;
use Ernestdefoe\Backdrop\LegacyImport;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class BackdropTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var list<string> */
    private array $temp = [];

    private bool $legacySchema = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-backdrop');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'third', 'email' => 'third@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        // MySQL commits DDL at once, and with it the test's transaction, so
        // nothing the import tests do is rolled back: undo it all by hand.
        if ($this->legacySchema) {
            $db = $this->database();
            $schema = $db->getSchemaBuilder();
            $schema->dropIfExists('cover_studio_user_data');

            if ($schema->hasColumn('users', 'cover')) {
                $schema->table('users', fn (Blueprint $table) => $table->dropColumn('cover'));
            }

            $db->table('users')->update(['backdrop_cover' => null, 'backdrop_x' => 50, 'backdrop_y' => 50, 'backdrop_zoom' => 1]);
            $db->table('users')->whereIn('id', [3, 4, 5])->delete();
            $db->table('settings')->where('key', 'like', 'tryhackx-cover-studio.%')->orWhere('key', 'like', 'ernestdefoe-backdrop.%')->delete();
            $db->table('group_permission')->where('group_id', 4)->whereIn('permission', ['tryhackx-cover-studio.setCover', 'ernestdefoe-backdrop.set'])->delete();
        }

        parent::tearDown();
    }

    private function image(int $width = 1200, int $height = 600, string $type = 'png'): UploadedFile
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 30, 120, 90));
        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.'.$type;
        $type === 'png' ? imagepng($gd, $path) : imagejpeg($gd, $path);
        $this->temp[] = $path;

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, "cover.$type", "image/$type");
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(int $userId, ?int $as, ?UploadedFile $file, array $fields = []): ResponseInterface
    {
        $request = $this->request('POST', "/api/users/$userId/backdrop", $as ? ['authenticatedAs' => $as] : []);

        if ($file) {
            $request = $request->withUploadedFiles(['cover' => $file]);
        }

        return $this->send($request->withParsedBody($fields));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coverIn(ResponseInterface $response): ?array
    {
        return json_decode((string) $response->getBody(), true)['data']['attributes']['backdrop'] ?? null;
    }

    private function disk(): string
    {
        return $this->tmpDir().'/public/assets/backdrop';
    }

    #[Test]
    public function the_legacy_import_runs_after_every_other_migration(): void
    {
        // The import writes every Backdrop column. In 1.0.0 it ran before the
        // FoF Upload columns existed, and enabling over Cover Studio failed.
        $migrations = glob(__DIR__.'/../../../migrations/*.php');
        sort($migrations);

        $importing = array_values(array_filter($migrations, fn ($file) => str_contains(file_get_contents($file), 'LegacyImport::class)->import()')));

        $this->assertSame([end($migrations)], $importing);
    }

    #[Test]
    public function a_member_sets_frames_and_removes_their_own_cover(): void
    {
        $response = $this->upload(2, 2, $this->image(), ['x' => '20', 'y' => '80', 'zoom' => '1.5']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $cover = $this->coverIn($response);
        $this->assertSame(['x' => 20, 'y' => 80, 'zoom' => 1.5], array_intersect_key($cover, ['x' => 0, 'y' => 0, 'zoom' => 0]));
        $this->assertStringEndsWith('.webp', $cover['url']);
        $this->assertStringEndsWith('-sm.webp', $cover['small']);

        $stored = User::find(2)->backdrop_cover;
        $this->assertFileExists($this->disk().'/'.$stored);
        $this->assertFileExists($this->disk().'/'.str_replace('.webp', '-sm.webp', $stored));

        // Reframing keeps the file.
        $response = $this->send($this->request('PATCH', '/api/users/2/backdrop', [
            'authenticatedAs' => 2,
            'json' => ['x' => 55, 'y' => 45, 'zoom' => 0.75],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0.75, (float) $this->coverIn($response)['zoom']);
        $this->assertSame($stored, User::find(2)->backdrop_cover);

        // A replacement deletes the old files.
        $this->upload(2, 2, $this->image(800, 400, 'jpeg'));
        $this->assertFileDoesNotExist($this->disk().'/'.$stored);
        $replacement = User::find(2)->backdrop_cover;

        $response = $this->send($this->request('DELETE', '/api/users/2/backdrop', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->coverIn($response));
        $this->assertNull(User::find(2)->backdrop_cover);
        $this->assertFileDoesNotExist($this->disk().'/'.$replacement);
    }

    #[Test]
    public function deleting_a_member_deletes_their_cover_files(): void
    {
        $this->upload(3, 3, $this->image());
        $stored = User::find(3)->backdrop_cover;
        $this->assertFileExists($this->disk().'/'.$stored);

        $response = $this->send($this->request('DELETE', '/api/users/3', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFileDoesNotExist($this->disk().'/'.$stored);
    }

    #[Test]
    public function large_images_are_stored_at_most_1920_wide(): void
    {
        $this->upload(2, 2, $this->image(3000, 1000));

        [$width] = getimagesize($this->disk().'/'.User::find(2)->backdrop_cover);
        $this->assertSame(1920, $width);
    }

    private function gif(int $width, int $height, int $frames): UploadedFile
    {
        $manager = \Intervention\Image\ImageManager::gd();
        $data = (string) $manager->animate(function ($animation) use ($manager, $width, $height, $frames) {
            for ($i = 0; $i < $frames; $i++) {
                $animation->add($manager->create($width, $height)->fill(sprintf('%06x', $i * 40000)), 0.1);
            }
        })->toGif();

        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.gif';
        file_put_contents($path, $data);
        $this->temp[] = $path;

        return new UploadedFile($path, strlen($data), UPLOAD_ERR_OK, 'cover.gif', 'image/gif');
    }

    private function gifFrom(string $data): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.gif';
        file_put_contents($path, $data);
        $this->temp[] = $path;

        return new UploadedFile($path, strlen($data), UPLOAD_ERR_OK, 'cover.gif', 'image/gif');
    }

    #[Test]
    public function animated_gifs_stay_animated_but_costly_ones_are_refused_before_decoding(): void
    {
        // 20 frames of 1500x1500 is a few KB of file and 45 million pixels of work.
        $this->assertSame(422, $this->upload(2, 2, $this->gif(1500, 1500, 20))->getStatusCode());

        // The same animation with bytes after its first frame that make a
        // spec-following block walk stop there (one frame, cheap), while the
        // decoder still reads all twenty.
        $plain = $this->gif(1500, 1500, 20);
        $data = (string) file_get_contents($plain->getStream()->getMetadata('uri'));
        $at = strpos($data, "\x21\xF9\x04", strpos($data, "\x21\xF9\x04") + 1);
        $crafted = substr($data, 0, $at)."\x21\xF9\x00\x21\x00\x00\x3B\x00".substr($data, $at);
        $this->assertSame(1, CoverValidator::gifFrames($crafted));
        $this->assertSame(422, $this->upload(2, 2, $this->gifFrom($crafted))->getStatusCode());

        // And a GIF with no frames at all.
        $this->assertSame(422, $this->upload(2, 2, $this->gifFrom("GIF89a\x01\x00\x01\x00\x00\x00\x00;"))->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);

        $this->assertSame(200, $this->upload(2, 2, $this->gif(400, 200, 5))->getStatusCode());
        $stored = User::find(2)->backdrop_cover;
        $this->assertStringEndsWith('.gif', $stored);
        $this->assertSame(5, CoverValidator::gifFrames((string) file_get_contents($this->disk().'/'.$stored)));
    }

    #[Test]
    public function very_tall_images_are_stored_whole_not_as_empty_files(): void
    {
        // Within core's pixel cap, but taller than WebP can encode.
        $this->assertSame(200, $this->upload(2, 2, $this->image(800, 17000))->getStatusCode());

        $stored = User::find(2)->backdrop_cover;
        [, $height] = getimagesize($this->disk().'/'.$stored);
        $this->assertLessThanOrEqual(4096, $height);
        $this->assertGreaterThan(0, filesize($this->disk().'/'.str_replace('.webp', '-sm.webp', $stored)));
    }

    #[Test]
    public function members_cannot_change_someone_elses_cover_but_admins_can(): void
    {
        $this->assertSame(403, $this->upload(3, 2, $this->image())->getStatusCode());
        $this->assertNull(User::find(3)->backdrop_cover);

        $this->assertSame(200, $this->upload(3, 1, $this->image())->getStatusCode());
        $this->assertNotNull(User::find(3)->backdrop_cover);
    }

    #[Test]
    public function guests_are_refused(): void
    {
        // A real guest session, CSRF token included, so the refusal is the endpoint's.
        $request = $this->requestWithCsrfToken($this->request('POST', '/api/users/2/backdrop'))
            ->withUploadedFiles(['cover' => $this->image()]);

        $this->assertSame(401, $this->send($request)->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);
    }

    #[Test]
    public function the_permission_gates_your_own_cover(): void
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-backdrop.set')->delete();

        $this->assertSame(403, $this->upload(2, 2, $this->image())->getStatusCode());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addPresets(int $count): array
    {
        $presets = [];

        for ($i = 0; $i < $count; $i++) {
            $response = $this->send(
                $this->request('POST', '/api/backdrop/presets', ['authenticatedAs' => 1])
                    ->withUploadedFiles(['cover' => $this->image()])
                    ->withParsedBody(['x' => '30', 'y' => '40', 'zoom' => '1.1'])
            );
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $presets = json_decode((string) $response->getBody(), true)['data'];
        }

        return $presets;
    }

    private function choose(int $userId, int $as, array $body): ResponseInterface
    {
        return $this->send($this->request('POST', "/api/users/$userId/backdrop/preset", ['authenticatedAs' => $as, 'json' => $body]));
    }

    #[Test]
    public function members_choose_from_the_gallery_and_keep_their_own_framing(): void
    {
        [$first, $second] = $this->addPresets(2);
        $this->assertSame(['x' => 30, 'y' => 40, 'zoom' => 1.1], array_intersect_key($first, ['x' => 0, 'y' => 0, 'zoom' => 0]));

        // Members can list it; guests cannot.
        $listed = json_decode((string) $this->send($this->request('GET', '/api/backdrop/presets', ['authenticatedAs' => 2]))->getBody(), true)['data'];
        $this->assertSame([$first['id'], $second['id']], array_column($listed, 'id'));
        $this->assertSame(401, $this->send($this->request('GET', '/api/backdrop/presets'))->getStatusCode());

        // Choosing with no frame takes the gallery's.
        $response = $this->choose(2, 2, ['preset' => $first['id']]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cover = $this->coverIn($response);
        $this->assertSame($first['id'], $cover['preset']);
        $this->assertSame($first['url'], $cover['url']);
        $this->assertSame(1.1, (float) $cover['zoom']);

        // Two members share one file, framed differently.
        $cover = $this->coverIn($this->choose(3, 3, ['preset' => $first['id'], 'x' => 90, 'zoom' => 2]));
        $this->assertSame([90.0, 40.0, 2.0], [(float) $cover['x'], (float) $cover['y'], (float) $cover['zoom']]);
        $this->assertSame(User::find(2)->backdrop_cover, User::find(3)->backdrop_cover);

        // Removing your cover never deletes the shared file.
        $this->send($this->request('DELETE', '/api/users/2/backdrop', ['authenticatedAs' => 2]));
        $this->assertFileExists($this->disk().'/'.User::find(3)->backdrop_cover);

        $this->assertSame(422, $this->choose(2, 2, ['preset' => 'nope'])->getStatusCode());
    }

    #[Test]
    public function choosing_a_gallery_cover_deletes_your_uploaded_one(): void
    {
        [$preset] = $this->addPresets(1);
        $this->upload(2, 2, $this->image());
        $uploaded = User::find(2)->backdrop_cover;

        $this->choose(2, 2, ['preset' => $preset['id']]);

        $this->assertFileDoesNotExist($this->disk().'/'.$uploaded);
    }

    #[Test]
    public function the_two_permissions_are_independent(): void
    {
        [$preset] = $this->addPresets(1);
        $db = $this->database();

        // Gallery only: upload refused, choosing allowed.
        $db->table('group_permission')->where('permission', 'ernestdefoe-backdrop.set')->delete();
        $this->assertSame(403, $this->upload(2, 2, $this->image())->getStatusCode());
        $this->assertSame(200, $this->choose(2, 2, ['preset' => $preset['id']])->getStatusCode());

        // Someone else's cover is a moderation action either way.
        $this->assertSame(403, $this->choose(3, 2, ['preset' => $preset['id']])->getStatusCode());
        $this->assertSame(200, $this->choose(3, 1, ['preset' => $preset['id']])->getStatusCode());
    }

    #[Test]
    public function without_either_permission_you_can_still_frame_or_remove_your_cover(): void
    {
        [$preset] = $this->addPresets(1);
        $this->database()->table('users')->where('id', 2)->update(['backdrop_cover' => 'presets/'.$preset['id'].'.webp']);
        $this->database()->table('group_permission')->whereIn('permission', ['ernestdefoe-backdrop.set', 'ernestdefoe-backdrop.choose'])->delete();

        $this->assertSame(403, $this->choose(2, 2, ['preset' => $preset['id']])->getStatusCode());
        $this->assertSame(403, $this->upload(2, 2, $this->image())->getStatusCode());
        $this->assertSame(200, $this->send($this->request('PATCH', '/api/users/2/backdrop', ['authenticatedAs' => 2, 'json' => ['x' => 10]]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('DELETE', '/api/users/2/backdrop', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);
    }

    #[Test]
    public function admins_reorder_reframe_and_delete_gallery_covers(): void
    {
        [$a, $b, $c] = $this->addPresets(3);
        $send = fn (string $method, string $path, array $json = [], int $as = 1) => $this->send(
            $this->request($method, '/api/backdrop/presets'.$path, ['authenticatedAs' => $as, 'json' => $json])
        );
        $ids = fn (ResponseInterface $r) => array_column(json_decode((string) $r->getBody(), true)['data'], 'id');

        $this->assertSame(403, $send('PUT', '', ['order' => [$c['id']]], 2)->getStatusCode());
        $this->assertSame([$c['id'], $a['id'], $b['id']], $ids($send('PUT', '', ['order' => [$c['id'], $a['id']]])));

        $framed = json_decode((string) $send('PATCH', '/'.$b['id'], ['x' => 5, 'y' => 6, 'zoom' => 3])->getBody(), true)['data'];
        $this->assertSame(3, $framed[2]['zoom']);

        $this->choose(2, 2, ['preset' => $a['id']]);
        $file = User::find(2)->backdrop_cover;

        $this->assertSame([$c['id'], $b['id']], $ids($send('DELETE', '/'.$a['id'])));
        $this->assertNull(User::find(2)->backdrop_cover);
        $this->assertFileDoesNotExist($this->disk().'/'.$file);
        $this->assertSame(422, $send('DELETE', '/'.$a['id'])->getStatusCode());
    }

    #[Test]
    public function only_admins_add_gallery_covers_and_the_gallery_has_a_limit(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/backdrop/presets', ['authenticatedAs' => 2])->withUploadedFiles(['cover' => $this->image()])
        );
        $this->assertSame(403, $response->getStatusCode());

        $full = array_map(fn ($i) => ['id' => "p$i", 'file' => "presets/p$i.webp", 'x' => 50, 'y' => 50, 'zoom' => 1], range(1, 24));
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('ernestdefoe-backdrop.presets', json_encode($full));

        $response = $this->send(
            $this->request('POST', '/api/backdrop/presets', ['authenticatedAs' => 1])->withUploadedFiles(['cover' => $this->image()])
        );
        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function bad_input_is_refused(): void
    {
        $this->assertSame(422, $this->upload(2, 2, null)->getStatusCode());
        $this->assertSame(422, $this->upload(2, 2, $this->image(), ['zoom' => '9'])->getStatusCode());
        $this->assertSame(422, $this->upload(2, 2, $this->image(), ['x' => 'left'])->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.png';
        file_put_contents($path, '<?php echo "not an image";');
        $this->temp[] = $path;
        $fake = new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'cover.png', 'image/png');
        $this->assertSame(422, $this->upload(2, 2, $fake)->getStatusCode());

        // Nothing to reframe yet.
        $response = $this->send($this->request('PATCH', '/api/users/2/backdrop', ['authenticatedAs' => 2, 'json' => ['x' => 10]]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_cover);
    }

    #[Test]
    public function members_are_limited_to_six_uploads_a_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(200, $this->upload(2, 2, $this->image(200, 100))->getStatusCode());
        }

        $this->assertSame(429, $this->upload(2, 2, $this->image(200, 100))->getStatusCode());
    }

    #[Test]
    public function listing_many_users_with_covers_adds_no_queries_per_user(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->database()->table('users')->where('id', $id)->update(['backdrop_cover' => "c$id.webp", 'backdrop_zoom' => 1.25]);
        }

        // The base TestCase fails the request if any query repeats per user.
        $response = $this->send($this->request('GET', '/api/users', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $users = json_decode((string) $response->getBody(), true)['data'];
        $this->assertCount(4, $users);

        foreach ($users as $user) {
            $this->assertStringEndsWith('c'.$user['id'].'.webp', $user['attributes']['backdrop']['url']);

            // Abilities are only worked out for yourself (or by a moderator).
            if ($user['id'] === '2') {
                $this->assertTrue($user['attributes']['canUploadBackdrop']);
                $this->assertTrue($user['attributes']['canChooseBackdrop']);
            } else {
                $this->assertArrayNotHasKey('canUploadBackdrop', $user['attributes']);
            }
        }
    }

    #[Test]
    public function the_forum_publishes_its_settings_and_guests_see_no_edit_flag(): void
    {
        $this->database()->table('users')->where('id', 2)->update(['backdrop_cover' => 'https://cdn.example/x.jpg']);

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame(
            ['height' => 220, 'heightMobile' => 160, 'overlay' => 'gradient', 'onCards' => true, 'onDirectory' => true, 'default' => null],
            $forum['data']['attributes']['backdrop']
        );

        $user = json_decode((string) $this->send($this->request('GET', '/api/users/2'))->getBody(), true);
        $this->assertArrayNotHasKey('canUploadBackdrop', $user['data']['attributes']);
        $this->assertArrayNotHasKey('canChooseBackdrop', $user['data']['attributes']);
        // An imported URL is served as it is, for both sizes.
        $this->assertSame('https://cdn.example/x.jpg', $user['data']['attributes']['backdrop']['small']);
    }

    #[Test]
    public function only_admins_manage_the_default_cover(): void
    {
        $post = fn (int $as) => $this->send(
            $this->request('POST', '/api/backdrop/default', ['authenticatedAs' => $as])
                ->withUploadedFiles(['cover' => $this->image()])
                ->withParsedBody(['zoom' => '0.6'])
        );

        $this->assertSame(403, $post(2)->getStatusCode());

        $response = $post(1);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0.6, json_decode((string) $response->getBody(), true)['data']['zoom']);

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame(0.6, $forum['data']['attributes']['backdrop']['default']['zoom']);

        $response = $this->send($this->request('DELETE', '/api/backdrop/default', ['authenticatedAs' => 1]));
        $this->assertNull(json_decode((string) $response->getBody(), true)['data']);
    }

    #[Test]
    public function covers_come_over_from_profile_cover_by_url_then_as_files(): void
    {
        $this->app();
        $this->legacySchema = true;
        $db = $this->database();
        $db->getSchemaBuilder()->table('users', fn (Blueprint $table) => $table->string('cover', 150)->nullable());

        // sycho/flarum-profile-cover kept the file name, served from assets/covers.
        $covers = $this->tmpDir().'/public/assets/covers';
        @mkdir($covers, 0755, true);
        copy($this->image()->getStream()->getMetadata('uri'), "$covers/legacy.png");
        $db->table('users')->where('id', 2)->update(['cover' => 'legacy.png']);
        $db->table('users')->where('id', 3)->update(['cover' => 'missing.png']);

        $import = $this->app()->getContainer()->make(LegacyImport::class);
        $this->assertSame(['coverStudio' => 0, 'sycho' => 2], $import->import());
        $this->assertStringEndsWith('/assets/covers/legacy.png', $db->table('users')->find(2)->backdrop_cover);

        // Only the file that exists is copied; the missing one stays a URL.
        $this->assertSame(1, $import->localize($this->app()->getContainer()->make(CoverStore::class)));
        $stored = $db->table('users')->find(2)->backdrop_cover;
        $this->assertStringEndsWith('.webp', $stored);
        $this->assertFileExists($this->disk().'/'.$stored);
        $this->assertFileExists("$covers/legacy.png");
        $this->assertStringEndsWith('/assets/covers/missing.png', $db->table('users')->find(3)->backdrop_cover);
    }

    #[Test]
    public function covers_come_over_from_cover_studio_without_overwriting_anything(): void
    {
        $this->app();
        $this->legacySchema = true;
        $schema = $this->database()->getSchemaBuilder();
        $schema->create('cover_studio_user_data', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('cover_file_id')->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->decimal('cover_focus_x', 5, 2)->default(50);
            $table->decimal('cover_focus_y', 5, 2)->default(50);
            $table->decimal('cover_zoom', 4, 2)->default(1);
        });

        $db = $this->database();
        $db->table('cover_studio_user_data')->insert([
            ['user_id' => 2, 'cover_file_id' => 7, 'cover_url' => 'https://cdn.example/two.jpg', 'cover_focus_x' => 70, 'cover_focus_y' => 30, 'cover_zoom' => 1.2],
            ['user_id' => 3, 'cover_file_id' => 8, 'cover_url' => 'https://cdn.example/three.jpg', 'cover_focus_x' => 10, 'cover_focus_y' => 10, 'cover_zoom' => 1],
            ['user_id' => 4, 'cover_file_id' => 9, 'cover_url' => 'javascript:alert(1)', 'cover_focus_x' => 50, 'cover_focus_y' => 50, 'cover_zoom' => 1],
        ]);
        $db->table('users')->insert(['id' => 5, 'username' => 'longurl', 'email' => 'long@machine.local', 'password' => 'unused', 'is_email_confirmed' => 1, 'joined_at' => '2026-01-01 00:00:00']);
        $db->table('cover_studio_user_data')->insert(['user_id' => 5, 'cover_file_id' => 10, 'cover_url' => 'https://cdn.example/'.str_repeat('a', 470).'.jpg', 'cover_focus_x' => 50, 'cover_focus_y' => 50, 'cover_zoom' => 1]);
        $db->table('users')->where('id', 3)->update(['backdrop_cover' => 'mine.webp']);
        $db->table('group_permission')->insert(['group_id' => 4, 'permission' => 'tryhackx-cover-studio.setCover']);
        $db->table('settings')->insert(['key' => 'tryhackx-cover-studio.cover_height', 'value' => '300']);

        $import = $this->app()->getContainer()->make(LegacyImport::class);
        // A 494-character URL fits too (Cover Studio's own column is 500 long).
        $this->assertSame(['coverStudio' => 2, 'sycho' => 0], $import->import());
        $this->assertSame('https://cdn.example/'.str_repeat('a', 470).'.jpg', $db->table('users')->find(5)->backdrop_cover);

        // It runs once. A member who removes the imported cover keeps it removed.
        $db->table('users')->where('id', 2)->update(['backdrop_cover' => null]);
        $this->assertSame(['coverStudio' => 0, 'sycho' => 0], $import->import());
        $this->assertNull($db->table('users')->find(2)->backdrop_cover);
        $db->table('users')->where('id', 2)->update(['backdrop_cover' => 'https://cdn.example/two.jpg']);

        $two = $db->table('users')->find(2);
        $this->assertSame('https://cdn.example/two.jpg', $two->backdrop_cover);
        $this->assertEquals([70, 30, 1.2], [$two->backdrop_x, $two->backdrop_y, $two->backdrop_zoom]);
        $this->assertSame('mine.webp', $db->table('users')->find(3)->backdrop_cover);
        $this->assertNull($db->table('users')->find(4)->backdrop_cover);

        $this->assertTrue($db->table('group_permission')->where(['group_id' => 4, 'permission' => 'ernestdefoe-backdrop.set'])->exists());
        $this->assertSame('300', $db->table('settings')->where('key', 'ernestdefoe-backdrop.height')->value('value'));

        // A remote URL has no local file, so nothing is copied.
        $this->assertSame(0, $import->localize($this->app()->getContainer()->make(CoverStore::class)));
    }
}
