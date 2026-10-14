<?php

namespace Ernestdefoe\Backdrop\Tests\integration\api;

use Ernestdefoe\Backdrop\CoverStore;
use Ernestdefoe\Backdrop\LegacyImport;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\Upload\File;
use Illuminate\Database\Schema\Blueprint;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * With FoF Upload enabled, member covers go through it.
 */
class FofUploadTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var list<string> */
    private array $temp = [];

    private bool $legacySchema = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-upload', 'ernestdefoe-backdrop');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
            ],
            // FoF Upload grants uploading to nobody by default.
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'fof-upload.upload'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        if ($this->legacySchema) {
            $db = $this->database();
            $db->getSchemaBuilder()->dropIfExists('cover_studio_user_data');
            $db->table('users')->update(['backdrop_cover' => null, 'backdrop_small' => null, 'backdrop_file_id' => null]);
            $db->table('users')->where('id', 3)->delete();
            $db->table('settings')->where('key', 'like', 'ernestdefoe-backdrop.%')->delete();
        }

        parent::tearDown();
    }

    private function image(): UploadedFile
    {
        $gd = imagecreatetruecolor(1600, 700);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 40, 90, 160));
        $path = tempnam(sys_get_temp_dir(), 'backdrop').'.jpg';
        imagejpeg($gd, $path);
        $this->temp[] = $path;

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'cover.jpg', 'image/jpeg');
    }

    private function upload(int $userId, int $as): ResponseInterface
    {
        return $this->send(
            $this->request('POST', "/api/users/$userId/backdrop", ['authenticatedAs' => $as])
                ->withUploadedFiles(['cover' => $this->image()])
                ->withParsedBody(['x' => '30'])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coverIn(ResponseInterface $response): ?array
    {
        return json_decode((string) $response->getBody(), true)['data']['attributes']['backdrop'] ?? null;
    }

    #[Test]
    public function a_member_upload_goes_through_fof_upload_into_their_media(): void
    {
        $response = $this->upload(2, 2);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $user = User::find(2);
        $file = File::find($user->backdrop_file_id);
        $this->assertNotNull($file, 'a FoF Upload file was created');
        $this->assertSame(2, (int) $file->actor_id);
        $this->assertFalse((bool) $file->hidden, 'it shows in My Media');
        $this->assertSame($file->url, $user->backdrop_cover);

        // Cards use FoF Upload's WebP thumbnail.
        $cover = $this->coverIn($response);
        $this->assertSame($file->url, $cover['url']);
        $this->assertSame($file->thumbnail_url, $cover['small']);
        $this->assertStringEndsWith('.webp', $cover['small']);
        $this->assertSame(30.0, (float) $cover['x']);
    }

    #[Test]
    public function a_moderator_setting_someones_cover_puts_the_file_in_their_media(): void
    {
        $this->assertSame(200, $this->upload(3, 1)->getStatusCode());

        $this->assertSame(3, (int) File::find(User::find(3)->backdrop_file_id)->actor_id);
    }

    #[Test]
    public function deleting_the_file_in_fof_upload_removes_the_cover(): void
    {
        $this->upload(2, 2);
        $file = File::find(User::find(2)->backdrop_file_id);

        // FoF Upload's own delete endpoint, as My Media uses it.
        $response = $this->send($this->request('DELETE', '/api/fof/upload/delete/'.$file->uuid, ['authenticatedAs' => 1]));
        $this->assertContains($response->getStatusCode(), [200, 204], (string) $response->getBody());

        $user = User::find(2);
        $this->assertNull($user->backdrop_cover);
        $this->assertNull($user->backdrop_file_id);
    }

    #[Test]
    public function removing_or_replacing_the_cover_leaves_the_file_in_my_media(): void
    {
        $this->upload(2, 2);
        $first = User::find(2)->backdrop_file_id;

        $this->upload(2, 2);
        $this->assertNotSame($first, User::find(2)->backdrop_file_id);
        $this->assertNotNull(File::find($first));

        $this->send($this->request('DELETE', '/api/users/2/backdrop', ['authenticatedAs' => 2]));
        $this->assertNull(User::find(2)->backdrop_file_id);
        $this->assertSame(2, File::query()->where('actor_id', 2)->count());
    }

    #[Test]
    public function without_fof_upload_permission_backdrop_stores_it_itself(): void
    {
        $this->database()->table('group_permission')->where('permission', 'fof-upload.upload')->delete();

        $this->assertSame(200, $this->upload(2, 2)->getStatusCode());

        $user = User::find(2);
        $this->assertFalse(CoverStore::isExternal($user->backdrop_cover));
        $this->assertNull($user->backdrop_file_id);
        $this->assertSame(0, File::query()->count());
    }

    #[Test]
    public function the_admin_can_turn_the_fof_upload_route_off(): void
    {
        $this->setting('ernestdefoe-backdrop.use_fof_upload', '0');

        $this->assertSame(200, $this->upload(2, 2)->getStatusCode());
        $this->assertNull(User::find(2)->backdrop_file_id);
        $this->assertSame(0, File::query()->count());
    }

    #[Test]
    public function choosing_a_gallery_cover_unlinks_the_fof_file(): void
    {
        $this->upload(2, 2);

        $presets = json_decode((string) $this->send(
            $this->request('POST', '/api/backdrop/presets', ['authenticatedAs' => 1])->withUploadedFiles(['cover' => $this->image()])
        )->getBody(), true)['data'];

        $response = $this->send($this->request('POST', '/api/users/2/backdrop/preset', ['authenticatedAs' => 2, 'json' => ['preset' => $presets[0]['id']]]));
        $this->assertSame(200, $response->getStatusCode());

        $user = User::find(2);
        $this->assertNull($user->backdrop_file_id);
        $this->assertNull($user->backdrop_small);
        $this->assertStringEndsWith('-sm.webp', $this->coverIn($response)['small']);
    }

    #[Test]
    public function cover_studio_covers_stay_fof_upload_files(): void
    {
        $this->app();
        $this->legacySchema = true;
        $db = $this->database();
        $db->getSchemaBuilder()->create('cover_studio_user_data', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedInteger('cover_file_id')->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('cover_thumb_url', 500)->nullable();
            $table->decimal('cover_focus_x', 5, 2)->default(50);
            $table->decimal('cover_focus_y', 5, 2)->default(50);
            $table->decimal('cover_zoom', 4, 2)->default(1);
        });
        $db->table('cover_studio_user_data')->insert([
            'user_id' => 2, 'cover_file_id' => 41, 'cover_url' => 'http://localhost/assets/files/x/cover.jpg',
            'cover_thumb_url' => 'http://localhost/assets/files/x/cover-thumb.webp', 'cover_focus_x' => 70, 'cover_focus_y' => 30, 'cover_zoom' => 1.2,
        ]);

        $import = $this->app()->getContainer()->make(LegacyImport::class);
        $this->assertSame(['coverStudio' => 1, 'sycho' => 0], $import->import());

        $user = $db->table('users')->find(2);
        $this->assertSame(41, (int) $user->backdrop_file_id);
        $this->assertSame('http://localhost/assets/files/x/cover-thumb.webp', $user->backdrop_small);

        // With FoF Upload in use, the file-copy step leaves it there.
        $this->assertSame(0, $import->localize($this->app()->getContainer()->make(CoverStore::class)));
        $this->assertSame('http://localhost/assets/files/x/cover.jpg', $db->table('users')->find(2)->backdrop_cover);
    }
}
