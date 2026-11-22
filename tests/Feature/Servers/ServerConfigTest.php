<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Minecraft\FakePlayers;
use App\Models\Server;
use App\Models\User;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerFiles;
use App\Servers\ServerLauncher;
use App\WorldStorage\Archives;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;
use ZipArchive;

/**
 * server.properties, whitelist / ops and a pack's run.sh / user_jvm_args.txt from the app
 * (#5377): the form starts from the saved world's files, only changes are kept, and the
 * next start writes them over the world's.
 */
class ServerConfigTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private User $admin;

    private const WORLD_PROPERTIES = "#Minecraft server properties\nmotd=ATM9 server\ndifficulty=normal\nmax-players=10\nwhite-list=false\nlevel-seed=777\nonline-mode=true\n";

    private const RUN_SH = "#!/bin/sh\njava @user_jvm_args.txt @libraries/net/neoforged/neoforge/21.1.1/unix_args.txt nogui \"\$@\"\n";

    private const JVM_ARGS = "# Xmx and Xms set the maximum and minimum RAM usage\n-Xmx4G\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    /**
     * A server whose current world was uploaded as a modpack zip in a single folder.
     */
    private function serverWithPack(): Server
    {
        $server = Server::factory()->create(['name' => 'ATM9', 'slug' => 'atm9']);

        $file = tempnam(sys_get_temp_dir(), 'mvm-pack').'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ATM9-server/server.properties', self::WORLD_PROPERTIES);
        $zip->addFromString('ATM9-server/run.sh', self::RUN_SH);
        $zip->addFromString('ATM9-server/user_jvm_args.txt', self::JVM_ARGS);
        $zip->addFromString('ATM9-server/whitelist.json', json_encode([['uuid' => FakePlayers::uuidFor('Alex'), 'name' => 'Alex']]));
        $zip->addFromString('ATM9-server/world/level.dat', 'level');
        $zip->close();

        $this->actingAs($this->admin)->post("/admin/servers/{$server->slug}/world", [
            'archive' => new UploadedFile($file, 'atm9.zip', 'application/zip', null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $server->refresh();
        $this->assertNotNull($server->current_world_archive_id, 'The pack was uploaded.');

        return $server;
    }

    /**
     * @return array<string, mixed> the edit form as it would be sent unchanged
     */
    private function unchangedForm(Server $server, array $overrides = []): array
    {
        $config = [];
        $this->actingAs($this->admin)->get("/admin/servers/{$server->slug}/edit")
            ->assertInertia(function (AssertableInertia $page) use (&$config) {
                $config = $page->toArray()['props']['config'];
            });

        return [
            'name' => $server->name,
            'slug' => $server->slug,
            'server_type' => $server->server_type,
            'location' => $server->location,
            'software' => 'custom',
            'start_command' => $server->start_command,
            'java_version' => (string) $server->java_version,
            'idle_minutes' => (string) $server->idle_minutes,
            'props' => $config['values'],
            'extra_properties' => $config['extra'],
            'whitelist_players' => $config['whitelist'],
            'ops_players' => $config['ops'],
            'run_sh' => $config['run_sh'],
            'user_jvm_args' => $config['user_jvm_args'],
            ...$overrides,
        ];
    }

    private function save(Server $server, array $form): void
    {
        $this->actingAs($this->admin)->put("/admin/servers/{$server->slug}", $form)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/servers');
    }

    private function startAndBoot(Server $server): void
    {
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);
        $cloud->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();
    }

    /**
     * @return array<string, string> remote path => contents
     */
    private function sentFiles(): array
    {
        return collect($this->shell()->files())->mapWithKeys(fn (array $f) => [$f['path'] => $f['contents']])->all();
    }

    public function test_the_form_starts_from_the_uploaded_packs_files(): void
    {
        $server = $this->serverWithPack();

        $this->actingAs($this->admin)->get("/admin/servers/{$server->slug}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('config.has_world_files', true)
                ->where('config.values.motd', 'ATM9 server')
                ->where('config.values.difficulty', 'normal')
                ->where('config.values.max-players', '10')
                ->where('config.values.pvp', '')
                ->where('config.world.white-list', 'false')
                ->where('config.extra', '')
                ->where('config.world_other', ['level-seed=777', 'online-mode=true'])
                ->where('config.whitelist', 'Alex')
                ->where('config.run_sh', self::RUN_SH)
                ->where('config.run_sh_pack', self::RUN_SH)
                ->where('config.run_sh_changed', false)
                ->where('config.user_jvm_args', self::JVM_ARGS));
    }

    public function test_saving_the_form_unchanged_keeps_following_the_world(): void
    {
        $server = $this->serverWithPack();

        $this->save($server, $this->unchangedForm($server));

        $server->refresh();
        $this->assertNull($server->properties);
        $this->assertNull($server->whitelist);
        $this->assertNull($server->ops);
        $this->assertNull($server->run_sh);
        $this->assertNull($server->user_jvm_args);
        $this->assertSame([], ServerFiles::overrideFiles($server), 'Nothing to write over the world.');
    }

    public function test_only_changes_are_kept_and_written_before_the_next_start(): void
    {
        $server = $this->serverWithPack();
        $form = $this->unchangedForm($server);
        $form['props']['motd'] = 'Friends only';
        $form['props']['white-list'] = 'true';
        $form['props']['pvp'] = 'false';
        $form['extra_properties'] = "level-seed=777\nspawn-monsters=false";
        $form['whitelist_players'] = "Alex\nsteve";
        $form['ops_players'] = 'Alex 2';
        $form['user_jvm_args'] = "-Xmx6G\n";

        $this->save($server, $form);

        $server->refresh();
        $this->assertSame(['motd' => 'Friends only', 'pvp' => 'false', 'white-list' => 'true', 'spawn-monsters' => 'false'], $server->properties);
        $this->assertSame([['uuid' => FakePlayers::uuidFor('Alex'), 'name' => 'Alex'], ['uuid' => FakePlayers::uuidFor('steve'), 'name' => 'steve']], $server->whitelist);
        $this->assertSame([['uuid' => FakePlayers::uuidFor('Alex'), 'name' => 'Alex', 'level' => 2]], $server->ops);
        $this->assertNull($server->run_sh, 'Left as the pack has it.');
        $this->assertSame("-Xmx6G\n", $server->user_jvm_args);

        $this->startAndBoot($server);

        $files = $this->sentFiles();
        $this->assertSame("motd=Friends only\npvp=false\nwhite-list=true\nspawn-monsters=false\n", $files[ServerFiles::DIR.'/server.properties']);
        $this->assertSame([
            ['uuid' => FakePlayers::uuidFor('Alex'), 'name' => 'Alex'],
            ['uuid' => FakePlayers::uuidFor('steve'), 'name' => 'steve'],
        ], json_decode($files[ServerFiles::DIR.'/whitelist.json'], true));
        $this->assertSame([['uuid' => FakePlayers::uuidFor('Alex'), 'name' => 'Alex', 'level' => 2, 'bypassesPlayerLimit' => false]], json_decode($files[ServerFiles::DIR.'/ops.json'], true));
        $this->assertSame("-Xmx6G\n", $files[ServerFiles::DIR.'/user_jvm_args.txt']);
        $this->assertArrayNotHasKey(ServerFiles::DIR.'/run.sh', $files);
        $this->assertStringContainsString('awk', $files['/root/.mvm/prepare.sh']);

        // The form now shows the changes on top of the world.
        $this->actingAs($this->admin)->get("/admin/servers/{$server->slug}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('config.values.motd', 'Friends only')
                ->where('config.values.difficulty', 'normal')
                ->where('config.extra', 'spawn-monsters=false')
                ->where('config.world_other', ['level-seed=777', 'online-mode=true'])
                ->where('config.whitelist', "Alex\nsteve")
                ->where('config.ops', 'Alex 2')
                ->where('config.user_jvm_args_changed', true));
    }

    public function test_clearing_a_field_gives_the_worlds_value_back(): void
    {
        $server = $this->serverWithPack();
        $form = $this->unchangedForm($server);
        $form['props']['motd'] = 'Changed';
        $this->save($server, $form);
        $this->assertSame(['motd' => 'Changed'], $server->refresh()->properties);

        $form['props']['motd'] = '';
        $this->save($server, $form);

        $this->assertNull($server->refresh()->properties);
    }

    public function test_a_run_sh_put_back_to_the_packs_is_not_written(): void
    {
        $server = $this->serverWithPack();
        $this->save($server, $this->unchangedForm($server, ['run_sh' => "#!/bin/sh\njava -Xmx6G -jar server.jar nogui\n"]));
        $this->assertNotNull($server->refresh()->run_sh);

        // "Use the pack's file" puts the pack's text back; Windows line endings don't count.
        $this->save($server, $this->unchangedForm($server, ['run_sh' => str_replace("\n", "\r\n", self::RUN_SH)]));

        $this->assertNull($server->refresh()->run_sh);
    }

    public function test_bad_settings_and_names_are_refused(): void
    {
        $server = $this->serverWithPack();
        $form = $this->unchangedForm($server);

        foreach ([
            [['props' => [...$form['props'], 'difficulty' => 'insane']], 'props.difficulty'],
            [['props' => [...$form['props'], 'max-players' => '0']], 'props.max-players'],
            [['props' => [...$form['props'], 'motd' => "two\nlines"]], 'props.motd'],
            [['extra_properties' => 'just words'], 'extra_properties'],
            [['extra_properties' => 'motd=sneaky'], 'extra_properties'],
            [['extra_properties' => 'Bad_Key=1'], 'extra_properties'],
            [['whitelist_players' => 'no spaces allowed? ok'], 'whitelist_players'],
            [['whitelist_players' => "Alex\nnobody_here"], 'whitelist_players'],
            [['ops_players' => 'Alex 7'], 'ops_players'],
            [['whitelist_players' => "Alex\nmojang_down"], 'whitelist_players'],
        ] as [$change, $field]) {
            $this->actingAs($this->admin)->put("/admin/servers/{$server->slug}", [...$form, ...$change])
                ->assertSessionHasErrors($field);
        }

        $this->assertNull($server->refresh()->properties);
        $this->assertNull($server->whitelist);
    }

    public function test_an_unknown_name_is_named_in_the_error(): void
    {
        $server = $this->serverWithPack();

        $this->actingAs($this->admin)->put("/admin/servers/{$server->slug}", $this->unchangedForm($server, ['whitelist_players' => "Alex\nNobodyAtAll"]))
            ->assertSessionHasErrors(['whitelist_players' => 'No Minecraft account is called NobodyAtAll.']);
    }

    public function test_the_whitelist_cant_be_turned_on_with_nobody_on_it(): void
    {
        $server = $this->serverWithPack();
        $form = $this->unchangedForm($server, ['whitelist_players' => '']);
        $form['props']['white-list'] = 'true';

        $this->actingAs($this->admin)->put("/admin/servers/{$server->slug}", $form)
            ->assertSessionHasErrors(['whitelist_players' => 'Add at least one player before turning the whitelist on, or nobody can join.']);
    }

    public function test_a_stop_records_the_worlds_files_as_saved(): void
    {
        $server = Server::factory()->create(['slug' => 'vanilla']);
        $this->startAndBoot($server);
        $run = $server->activeRun()->firstOrFail();

        $this->app->make(ServerLauncher::class)->requestStop($run, 'admin');

        $archive = $server->refresh()->currentWorldArchive;
        $this->assertNotNull($archive);
        $this->assertSame(['server.properties' => FakeRemoteShell::SAVED_PROPERTIES], $archive->files);

        $this->actingAs($this->admin)->get('/admin/servers/vanilla/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('config.values.motd', 'fake')
                ->where('config.values.difficulty', 'easy')
                ->where('config.world_other', ['level-seed=42']));
    }

    public function test_a_new_server_can_set_properties_without_a_world(): void
    {
        $this->actingAs($this->admin)->post('/admin/servers', [
            'name' => 'Fresh', 'slug' => 'fresh', 'server_type' => 'cx33', 'location' => 'fsn1',
            'software' => 'custom', 'start_command' => './run.sh', 'java_version' => '21', 'idle_minutes' => '15',
            'props' => ['motd' => 'Brand new', 'difficulty' => 'hard'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['motd' => 'Brand new', 'difficulty' => 'hard'], Server::query()->sole()->properties);
    }

    public function test_properties_parse_and_escape_like_java(): void
    {
        $this->assertSame(
            ['motd' => 'A \\ server', 'level-name' => 'world', 'note' => "Caf\u{e9}"],
            ServerFiles::parseProperties("# comment\n! also\nmotd=A \\\\ server\nlevel-name : world\nnote=Caf\\u00e9\n\n"),
        );
        $this->assertSame('A \\\\ server', ServerFiles::escape('A \\ server'));
    }

    public function test_only_the_managed_files_are_read_from_a_zip(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mvm-zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('server.properties', "motd=top\n");
        $zip->addFromString('eula.txt', 'eula=true');
        $zip->addFromString('ops.json', str_repeat('x', ServerFiles::MAX_BYTES + 1));
        $zip->close();

        $this->assertSame(['server.properties' => "motd=top\n"], ServerFiles::fromZip($file));
    }

    public function test_an_old_archive_without_files_says_so(): void
    {
        $server = Server::factory()->create(['slug' => 'old']);
        $archives = $this->app->make(Archives::class);
        $path = $archives->newPath($server, 'upload.zip');
        $this->app->make(WorldStorage::class)->disk()->put($path, 'x');
        $archive = $archives->record($server, $path, 1, hash('sha256', 'x'), 'upload');
        $archive->files = null;
        $archive->save();

        $this->actingAs($this->admin)->get('/admin/servers/old/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('config.has_world', true)
                ->where('config.has_world_files', false));
    }
}
