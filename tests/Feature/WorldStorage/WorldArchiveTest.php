<?php

namespace Tests\Feature\WorldStorage;

use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Models\WorldArchive;
use App\WorldStorage\Archives;
use App\WorldStorage\ZipInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

class WorldArchiveTest extends WorldStorageTestCase
{
    private function upload(string $zipPath, string $name = 'world.zip'): UploadedFile
    {
        return new UploadedFile($zipPath, $name, 'application/zip', null, true);
    }

    public function test_an_uploaded_zip_becomes_the_current_world(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();
        $zip = $this->makeZip(['world/level.dat' => 'level', 'server.properties' => 'motd=hi']);

        $this->actingAs($admin)->from('/admin/servers/atm9/edit')
            ->post('/admin/servers/atm9/world', ['archive' => $this->upload($zip)])
            ->assertRedirect('/admin/servers/atm9/edit')
            ->assertSessionHasNoErrors();

        $archive = WorldArchive::query()->sole();
        $this->assertSame($archive->id, $server->fresh()?->current_world_archive_id);
        $this->assertSame('upload', $archive->source);
        $this->assertSame($admin->id, $archive->uploaded_by);
        $this->assertSame(hash_file('sha256', $zip), $archive->sha256);
        $this->assertSame(filesize($zip), $archive->size);
        $this->assertStringStartsWith('atm9/', $archive->path);
        $this->assertStringEndsWith('-upload.zip', $archive->path);
        $this->assertFileEquals($zip, $this->storageRoot.'/'.$archive->path);

        $this->actingAs($admin)->get('/admin/servers/atm9/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('world.archives.0.id', $archive->id)
                ->where('world.archives.0.current', true)
                ->where('world.storageProblem', null));
    }

    public function test_a_zip_that_would_unpack_outside_the_folder_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();

        foreach (['../escape.txt', 'world/../../escape.txt', '/etc/passwd'] as $name) {
            $zip = $this->makeZip(['ok.txt' => 'fine', $name => 'bad']);

            $this->actingAs($admin)->post('/admin/servers/atm9/world', ['archive' => $this->upload($zip)])
                ->assertSessionHasErrors('archive');
        }

        $this->assertSame(0, WorldArchive::query()->count());
        $this->assertDirectoryDoesNotExist($this->storageRoot.'/atm9');
    }

    public function test_zip_names_are_checked_entry_by_entry(): void
    {
        $this->assertTrue(ZipInspector::isSafeName('world/region/r.0.0.mca'));
        $this->assertTrue(ZipInspector::isSafeName('mods/..hidden.jar'));
        $this->assertFalse(ZipInspector::isSafeName('..'));
        $this->assertFalse(ZipInspector::isSafeName('a/../../b'));
        $this->assertFalse(ZipInspector::isSafeName('C:/Windows/x'));
        $this->assertFalse(ZipInspector::isSafeName('world\\..\\x'));
        $this->assertFalse(ZipInspector::isSafeName("a\0b"));
    }

    public function test_a_symlink_in_the_zip_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();
        $zip = $this->makeZip(['link' => '/etc/shadow']);
        $archive = new \ZipArchive;
        $archive->open($zip);
        $archive->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $archive->close();

        $this->actingAs($admin)->post('/admin/servers/atm9/world', ['archive' => $this->upload($zip)])
            ->assertSessionHasErrors(['archive' => 'The zip has a symbolic link (link), which isn\'t allowed.']);
    }

    public function test_not_a_zip_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();
        $fake = tempnam(sys_get_temp_dir(), 'mvm').'.zip';
        file_put_contents($fake, 'not a zip');

        $this->actingAs($admin)->post('/admin/servers/atm9/world', ['archive' => $this->upload($fake)])
            ->assertSessionHasErrors(['archive' => 'That file isn\'t a zip archive.']);
    }

    public function test_upload_needs_storage_set_up_and_the_server_stopped(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'atm9']);
        $zip = $this->makeZip(['level.dat' => 'x']);

        $this->actingAs($admin)->post('/admin/servers/atm9/world', ['archive' => $this->upload($zip)])
            ->assertSessionHasErrors(['archive' => 'World storage isn\'t set up yet: fill it in under Admin → World storage.']);

        $this->saveSettings();
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = $server->id;
        $run->status = RunStatus::Online;
        $run->save();

        $this->actingAs($admin)->post('/admin/servers/atm9/world', ['archive' => $this->upload($zip)])
            ->assertSessionHasErrors('archive');
        $this->assertSame(0, WorldArchive::query()->count());
    }

    public function test_players_cant_upload_or_download(): void
    {
        $player = User::factory()->create();
        $server = Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();
        $archive = app(Archives::class)->record($server, 'atm9/x.zip', 1, str_repeat('a', 64), 'upload');

        // Guests first: the test client stays signed in after actingAs().
        $this->get("/admin/servers/atm9/world/{$archive->id}")->assertRedirect('/login');
        $this->actingAs($player)->post('/admin/servers/atm9/world', ['archive' => $this->upload($this->makeZip(['a' => 'b']))])
            ->assertNotFound();
        $this->actingAs($player)->get("/admin/servers/atm9/world/{$archive->id}")->assertNotFound();
    }

    public function test_an_admin_downloads_an_archive_through_the_app(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'atm9']);
        $other = Server::factory()->create(['slug' => 'other']);
        $this->saveSettings();
        File::ensureDirectoryExists($this->storageRoot.'/atm9');
        file_put_contents($this->storageRoot.'/atm9/20261122-180000-abcdef-save.tar.gz', 'archive-bytes');
        $archive = app(Archives::class)->record($server, 'atm9/20261122-180000-abcdef-save.tar.gz', 13, hash('sha256', 'archive-bytes'), 'save');

        $response = $this->actingAs($admin)->get("/admin/servers/atm9/world/{$archive->id}");
        $response->assertOk()->assertDownload('20261122-180000-abcdef-save.tar.gz');
        $this->assertSame('archive-bytes', $response->streamedContent());

        // Another server's URL doesn't reach it.
        $this->actingAs($admin)->get("/admin/servers/{$other->slug}/world/{$archive->id}")->assertNotFound();
    }

    public function test_only_the_newest_three_are_kept(): void
    {
        $server = Server::factory()->create(['slug' => 'atm9']);
        $this->saveSettings();
        $archives = app(Archives::class);
        File::ensureDirectoryExists($this->storageRoot.'/atm9');

        $made = [];
        for ($i = 1; $i <= 5; $i++) {
            $path = "atm9/{$i}-save.tar.gz";
            file_put_contents($this->storageRoot.'/'.$path, "world {$i}");
            $made[] = $archives->record($server, $path, 7, hash('sha256', "world {$i}"), 'save');
        }

        $this->assertSame([5, 4, 3], WorldArchive::query()->orderByDesc('id')->get()->map(fn (WorldArchive $a) => (int) $a->path[5])->all());
        $this->assertFileDoesNotExist($this->storageRoot.'/atm9/1-save.tar.gz');
        $this->assertFileDoesNotExist($this->storageRoot.'/atm9/2-save.tar.gz');
        $this->assertFileExists($this->storageRoot.'/atm9/3-save.tar.gz');
        $this->assertSame($made[4]->id, $server->fresh()?->current_world_archive_id);
    }

    public function test_deleting_a_server_keeps_its_worlds_unless_the_box_is_ticked(): void
    {
        $admin = User::factory()->admin()->create();
        $this->saveSettings();
        File::ensureDirectoryExists($this->storageRoot.'/keep-me');
        File::ensureDirectoryExists($this->storageRoot.'/drop-me');
        file_put_contents($this->storageRoot.'/keep-me/a.zip', 'a');
        file_put_contents($this->storageRoot.'/drop-me/b.zip', 'b');
        $keep = Server::factory()->create(['slug' => 'keep-me']);
        $drop = Server::factory()->create(['slug' => 'drop-me']);
        app(Archives::class)->record($keep, 'keep-me/a.zip', 1, hash('sha256', 'a'), 'upload');
        app(Archives::class)->record($drop, 'drop-me/b.zip', 1, hash('sha256', 'b'), 'upload');

        $this->actingAs($admin)->delete('/admin/servers/keep-me')->assertRedirect('/admin/servers');
        $this->assertFileExists($this->storageRoot.'/keep-me/a.zip');

        $this->actingAs($admin)->delete('/admin/servers/drop-me', ['delete_archives' => '1'])->assertRedirect('/admin/servers');
        $this->assertFileDoesNotExist($this->storageRoot.'/drop-me/b.zip');
        $this->assertSame(0, Server::query()->count());
    }
}
