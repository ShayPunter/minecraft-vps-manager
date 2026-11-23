<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Minecraft\FakeVersions;
use App\Minecraft\Modrinth\FakeModrinth;
use App\Minecraft\Modrinth\ModrinthSource;
use App\Minecraft\Software;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\ServerRunLog;
use App\Models\User;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use App\WorldStorage\Archives;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Modpacks (#5352): a server-pack zip URL (installer-style packs like All the Mods 10
 * included) and Modrinth packs by name. The form, the Java and memory the app works out,
 * and what prepare.sh does on a first start without a saved world.
 */
class ModpackInstallTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const ATM10 = 'https://mediafilez.forgecdn.net/files/7012/345/ServerFiles-8.6.zip';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
        $this->modrinth()->reset();
    }

    private function modrinth(): FakeModrinth
    {
        $modrinth = $this->app->make(ModrinthSource::class);
        $this->assertInstanceOf(FakeModrinth::class, $modrinth);

        return $modrinth;
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return [
            'name' => 'All the Mods 10',
            'slug' => 'atm10',
            'server_type' => 'cx33', // 8 GB
            'location' => 'fsn1',
            'software' => 'pack_url',
            'pack_url' => self::ATM10,
            'java_version' => '21',
            'idle_minutes' => '15',
            ...$overrides,
        ];
    }

    private function startAndBoot(Server $server): ServerRun
    {
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);
        $cloud->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();

        return $run->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return collect($this->shell()->files())->mapWithKeys(fn (array $f): array => [$f['path'] => (string) $f['contents']])->all();
    }

    public function test_a_server_pack_url_starts_with_mvm_launch_and_memory_from_the_type(): void
    {
        $admin = User::factory()->admin()->create();

        // Its own start command is ignored; the Java comes from the form.
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['start_command' => 'rm -rf /', 'minecraft_version' => '26.3']))
            ->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame(Software::PackUrl, $server->software);
        $this->assertSame(self::ATM10, $server->pack_url);
        $this->assertSame(21, $server->java_version);
        $this->assertSame('sh mvm-launch.sh', $server->start_command);
        $this->assertSame(6400, $server->heap_mb);
        $this->assertNull($server->minecraft_version);
        $this->assertNull($server->modrinth_version_id);
    }

    public function test_a_server_pack_url_must_be_https_without_quotes_and_needs_a_java(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['pack_url' => 'http://example.com/pack.zip']))
            ->assertSessionHasErrors('pack_url');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['pack_url' => "https://example.com/pack.zip';reboot;'"]))
            ->assertSessionHasErrors('pack_url');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['pack_url' => '']))
            ->assertSessionHasErrors(['pack_url' => "Paste the server pack's download link."]);
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['java_version' => '']))
            ->assertSessionHasErrors('java_version');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_a_modrinth_pack_gets_the_java_its_minecraft_version_needs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid([
            'name' => 'Cobble', 'slug' => 'cobble', 'software' => 'modrinth', 'java_version' => '17', 'pack_url' => self::ATM10,
            'modrinth_project_id' => 'AAAAAAAA', 'modrinth_version_id' => 'AAAAAAA2', 'server_type' => 'cax31',
        ]))->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame(Software::Modrinth, $server->software);
        $this->assertSame('AAAAAAAA', $server->modrinth_project_id);
        $this->assertSame('AAAAAAA2', $server->modrinth_version_id);
        $this->assertSame('Cobble Pack', $server->modrinth_title);
        $this->assertSame('26.3', $server->minecraft_version);
        $this->assertSame(25, $server->java_version, 'From the version, not the form.');
        $this->assertSame(13056, $server->heap_mb);
        $this->assertSame('sh mvm-launch.sh', $server->start_command);
        $this->assertNull($server->pack_url);
    }

    public function test_a_modrinth_version_must_belong_to_the_picked_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $modrinth = ['software' => 'modrinth', 'modrinth_project_id' => 'AAAAAAAA', 'modrinth_version_id' => 'BBBBBBB5'];

        $this->actingAs($admin)->post('/admin/servers', $this->valid($modrinth))
            ->assertSessionHasErrors(['modrinth_version_id' => 'That version belongs to another modpack. Pick the version again.']);
        $this->actingAs($admin)->post('/admin/servers', $this->valid([...$modrinth, 'modrinth_version_id' => 'ZZZZZZZZ']))
            ->assertSessionHasErrors(['modrinth_version_id' => 'Modrinth has no such modpack or version.']);
        $this->actingAs($admin)->post('/admin/servers', $this->valid([...$modrinth, 'modrinth_project_id' => '']))
            ->assertSessionHasErrors(['modrinth_project_id' => 'Search for a modpack and pick one.']);
        $this->actingAs($admin)->post('/admin/servers', $this->valid([...$modrinth, 'modrinth_version_id' => 'x; ls']))
            ->assertSessionHasErrors('modrinth_version_id');

        $this->modrinth()->failWith("Couldn't reach Modrinth: timeout");
        $this->actingAs($admin)->post('/admin/servers', $this->valid([...$modrinth, 'modrinth_version_id' => 'AAAAAAA2']))
            ->assertSessionHasErrors(['modrinth_version_id' => "Couldn't reach Modrinth: timeout Try again in a minute."]);

        $this->assertSame(0, Server::query()->count());
    }

    public function test_switching_to_vanilla_clears_the_pack_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/servers', $this->valid());
        $server = Server::query()->sole();

        $this->actingAs($admin)->put("/admin/servers/{$server->slug}", $this->valid(['software' => 'vanilla', 'minecraft_version' => '26.3']))
            ->assertRedirect('/admin/servers');

        $server->refresh();
        $this->assertSame(Software::Vanilla, $server->software);
        $this->assertNull($server->pack_url);
        $this->assertNull($server->heap_mb);
        $this->assertSame('java -Xms6400M -Xmx6400M -jar server.jar nogui', $server->start_command);
    }

    public function test_the_first_start_of_a_server_pack_downloads_checks_and_runs_its_installer(): void
    {
        $server = Server::factory()->create(['slug' => 'atm10', 'software' => Software::PackUrl, 'pack_url' => self::ATM10, 'heap_mb' => 6400, 'start_command' => 'sh mvm-launch.sh']);

        $run = $this->startAndBoot($server);

        $this->assertSame(RunStatus::Online, $run->status);
        $script = $this->files()['/root/.mvm/prepare.sh'];
        $this->assertStringContainsString("echo 'Installing Server pack from mediafilez.forgecdn.net'", $script);
        $this->assertStringContainsString("U='".self::ATM10."'", $script);
        $this->assertStringContainsString('check_zip "$M/work/pack.zip" "The server pack"', $script);
        $this->assertStringContainsString("-name 'neoforge-*-installer.jar' -o -name 'forge-*-installer.jar'", $script);
        $this->assertStringContainsString('java -jar "$inst" --installServer', $script);
        $this->assertStringContainsString('> "$DIR/mvm-launch.sh"', $script);
        $this->assertStringContainsString("HEAP=6400\n", $script);
        // Install, then memory, then the admin's files, then the start.
        $this->assertLessThan(strpos($script, 'HEAP=6400'), strpos($script, 'mvm-launch.sh"'));
        $this->assertLessThan(strpos($script, 'systemctl start "$UNIT"'), strpos($script, 'HEAP=6400'));
        $this->assertArrayNotHasKey('/root/.mvm/mrfiles', $this->files());
    }

    public function test_the_first_start_of_a_modrinth_pack_checks_every_file_and_installs_its_loader(): void
    {
        $server = Server::factory()->create(['slug' => 'cobble', 'software' => Software::Modrinth, 'modrinth_project_id' => 'AAAAAAAA', 'modrinth_version_id' => 'AAAAAAA1', 'modrinth_title' => 'Cobble Pack', 'minecraft_version' => '1.21.11', 'heap_mb' => 6400]);

        $this->startAndBoot($server);

        $files = $this->files();
        $script = $files['/root/.mvm/prepare.sh'];
        $this->assertStringContainsString("echo 'Installing Cobble Pack (Fabric 0.20.1, Minecraft 1.21.11)'", $script);
        $this->assertStringContainsString("get 'https://cdn.modrinth.com/data/AAAAAAAA/versions/AAAAAAA1/pack.mrpack' \"\$M/work/pack.mrpack\" sha512 '".FakeModrinth::SHA512."'", $script);
        $this->assertStringContainsString('done < "$M/mrfiles"', $script);
        $this->assertStringContainsString('for o in overrides server-overrides; do', $script);
        $this->assertStringContainsString("\"\$DIR\"/server.jar sha1 '".FakeVersions::SHA1."'", $script);
        $this->assertStringContainsString("java -jar \"\$M/work/fabric-installer.jar\" server -mcversion '1.21.11' -loader '0.20.1' -dir \"\$DIR\"", $script);
        $this->assertSame(
            FakeModrinth::SHA512." https://cdn.modrinth.com/data/gvQqBUqZ/versions/x/lithium-0.15.0.jar mods/lithium-0.15.0.jar\n"
            .FakeModrinth::SHA512." https://github.com/example/cobble/raw/main/settings.json config/Cobble Pack/settings.json\n",
            $files['/root/.mvm/mrfiles'],
        );
        $this->assertStringContainsString('"$M/mrfiles"; }', $script, 'The cleanup trap deletes the list.');
        // A real .mrpack's overrides unpacked with no permissions at all.
        $this->assertLessThan(strpos($script, 'chown -R minecraft'), strpos($script, 'chmod -R u+rwX "$DIR"'));
    }

    public function test_a_neoforge_modrinth_pack_runs_its_installer_in_the_server_folder(): void
    {
        $server = Server::factory()->create(['slug' => 'frontier', 'software' => Software::Modrinth, 'modrinth_project_id' => 'BBBBBBBB', 'modrinth_version_id' => 'BBBBBBB5', 'minecraft_version' => '1.21.11', 'heap_mb' => 6400]);

        $this->startAndBoot($server);

        $script = $this->files()['/root/.mvm/prepare.sh'];
        $this->assertStringContainsString("get 'https://maven.neoforged.net/releases/net/neoforged/neoforge/21.11.4/neoforge-21.11.4-installer.jar' \"\$M/work\"/neoforge-installer.jar sha256", $script);
        $this->assertStringContainsString('if ! (cd "$DIR" && java -jar "$M/work/neoforge-installer.jar" --installServer); then echo "The NeoForge 21.11.4 installer failed."; exit 25; fi', $script);
        $this->assertStringNotContainsString('server.jar sha1', $script, 'NeoForge fetches Minecraft itself.');
    }

    public function test_modrinth_down_at_start_is_tried_again_and_a_broken_pack_gives_up(): void
    {
        $server = Server::factory()->create(['slug' => 'cobble', 'software' => Software::Modrinth, 'modrinth_project_id' => 'AAAAAAAA', 'modrinth_version_id' => 'AAAAAAA2', 'heap_mb' => 6400]);
        $this->modrinth()->failWith("Couldn't reach Modrinth: timeout");

        $run = $this->startAndBoot($server);

        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNull($run->prepare_queued_at, 'Queued again on the next check.');
        $this->assertSame([], $this->shell()->commands());
        $this->assertSame(
            ["Couldn't read the Modrinth pack yet, trying again: Couldn't reach Modrinth: timeout"],
            ServerRunLog::query()->where('server_run_id', $run->id)->pluck('line')->all(),
        );

        $this->modrinth()->failWith('The pack can\'t be installed: Refusing pack file path [../../etc/cron.d/x].', permanent: true);
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('The Modrinth pack can\'t be installed: The pack can\'t be installed: Refusing pack file path', (string) $run->error);
    }

    public function test_a_saved_world_is_never_reinstalled_but_the_memory_still_follows_the_type(): void
    {
        $server = Server::factory()->create(['slug' => 'atm10', 'software' => Software::PackUrl, 'pack_url' => self::ATM10, 'heap_mb' => 13056]);
        $archives = $this->app->make(Archives::class);
        $path = $archives->newPath($server, 'upload.zip');
        $this->app->make(WorldStorage::class)->disk()->put($path, 'a saved world');
        $archives->record($server, $path, 13, hash('sha256', 'a saved world'), 'upload');

        $this->startAndBoot($server->refresh());

        $script = $this->files()['/root/.mvm/prepare.sh'];
        $this->assertStringNotContainsString('Installing', $script);
        $this->assertStringNotContainsString(self::ATM10, $script);
        $this->assertStringContainsString("HEAP=13056\n", $script);
    }

    public function test_a_pack_install_gets_a_longer_boot_timeout(): void
    {
        $pack = Server::factory()->create(['slug' => 'atm10', 'software' => Software::PackUrl, 'pack_url' => self::ATM10, 'heap_mb' => 6400]);
        $custom = Server::factory()->create(['slug' => 'plain']);
        $this->shell()->failMatching('prepare.sh', 75, 'still installing'); // busy: never set up
        $packRun = $this->app->make(ServerLauncher::class)->start($pack, null, null)->refresh();
        $customRun = $this->app->make(ServerLauncher::class)->start($custom, null, null)->refresh();

        $this->travel(30)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(RunStatus::Failed, $customRun->refresh()->status);
        $this->assertNotSame(RunStatus::Failed, $packRun->refresh()->status, '45 minutes for a pack install.');

        $this->travel(16)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $packRun->refresh();
        $this->assertSame(RunStatus::Failed, $packRun->status);
        $this->assertStringContainsString('within 45 minutes', (string) $packRun->error);
    }

    public function test_the_modrinth_picker_searches_and_lists_versions_for_admins_only(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->actingAs($admin)->getJson('/admin/modrinth/search?q=cobble')
            ->assertOk()
            ->assertJsonPath('hits.0.id', 'AAAAAAAA')
            ->assertJsonPath('hits.0.title', 'Cobble Pack')
            ->assertJsonCount(1, 'hits');
        $this->actingAs($admin)->getJson('/admin/modrinth/projects/AAAAAAAA/versions')
            ->assertOk()
            ->assertJsonPath('versions', [
                ['id' => 'AAAAAAA2', 'name' => 'Cobble Pack 2.0.0', 'version_number' => '2.0.0', 'minecraft_version' => '26.3', 'loader' => 'fabric'],
                ['id' => 'AAAAAAA1', 'name' => 'Cobble Pack 1.0.0', 'version_number' => '1.0.0', 'minecraft_version' => '1.21.11', 'loader' => 'fabric'],
            ]);
        $this->actingAs($admin)->getJson('/admin/modrinth/projects/ZZZZZZZZ/versions')->assertNotFound();

        $this->modrinth()->failWith("Couldn't reach Modrinth: timeout");
        $this->actingAs($admin)->getJson('/admin/modrinth/search?q=x')
            ->assertStatus(502)
            ->assertJsonPath('message', "Couldn't reach Modrinth: timeout");

        $this->actingAs($player)->getJson('/admin/modrinth/search?q=cobble')->assertNotFound();
    }

    public function test_the_edit_page_shows_the_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'cobble', 'software' => Software::Modrinth, 'modrinth_project_id' => 'AAAAAAAA', 'modrinth_version_id' => 'AAAAAAA2', 'modrinth_title' => 'Cobble Pack', 'heap_mb' => 6400]);

        $this->actingAs($admin)->get("/admin/servers/{$server->slug}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('server.software', 'modrinth')
                ->where('server.modrinth_project_id', 'AAAAAAAA')
                ->where('server.modrinth_version_id', 'AAAAAAA2')
                ->where('server.modrinth_title', 'Cobble Pack')
                ->where('server.heap_mb', 6400));
    }
}
