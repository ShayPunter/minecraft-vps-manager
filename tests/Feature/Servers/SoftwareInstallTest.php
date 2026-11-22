<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Minecraft\FakeVersions;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
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
 * New servers from Vanilla, Paper or Fabric (#5351): the form, the Java and memory the app
 * works out, and the install prepare.sh runs on a first start without a saved world.
 */
class SoftwareInstallTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
    }

    private function versions(): FakeVersions
    {
        $versions = $this->app->make(VersionSource::class);
        $this->assertInstanceOf(FakeVersions::class, $versions);

        return $versions;
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
            'name' => 'Paper survival',
            'slug' => 'paper',
            'description' => '',
            'icon_url' => '',
            'server_type' => 'cx33', // 8 GB
            'location' => 'fsn1',
            'software' => 'paper',
            'minecraft_version' => '26.3',
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

    private function prepareScript(): string
    {
        $files = collect($this->shell()->files())->keyBy('path');

        return (string) $files['/root/.mvm/prepare.sh']['contents'];
    }

    public function test_the_form_offers_each_softwares_versions_newest_first(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/servers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('software.options', [
                    ['value' => 'vanilla', 'label' => 'Vanilla'],
                    ['value' => 'paper', 'label' => 'Paper'],
                    ['value' => 'fabric', 'label' => 'Fabric'],
                    ['value' => 'custom', 'label' => 'My own start command'],
                ])
                ->where('software.versions.paper', ['26.3', '26.2', '1.21.11'])
                ->where('software.error', null));
    }

    public function test_the_form_says_when_the_version_lists_cant_be_loaded(): void
    {
        $admin = User::factory()->admin()->create();
        $this->versions()->failWith("Couldn't reach Mojang's version list: timeout");

        $this->actingAs($admin)->get('/admin/servers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('software.versions.vanilla', [])
                ->where('software.error', "Couldn't reach Mojang's version list: timeout"));
    }

    public function test_a_paper_server_gets_the_java_its_version_needs_and_memory_from_the_type(): void
    {
        $admin = User::factory()->admin()->create();

        // The form's own start command and Java are ignored for an installed server.
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['start_command' => 'rm -rf /', 'java_version' => '17']))
            ->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame(Software::Paper, $server->software);
        $this->assertSame('26.3', $server->minecraft_version);
        $this->assertSame(25, $server->java_version);
        $this->assertSame('java -Xms6400M -Xmx6400M -jar server.jar nogui', $server->start_command);
    }

    public function test_a_fabric_server_runs_the_fabric_launcher(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['slug' => 'fabric', 'software' => 'fabric', 'minecraft_version' => '1.21.11', 'server_type' => 'cax31']))
            ->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame(21, $server->java_version);
        // 16 GB: a fifth kept back, in 256 MB steps.
        $this->assertSame('java -Xms13056M -Xmx13056M -jar fabric-server-launch.jar nogui', $server->start_command);
    }

    public function test_the_memory_follows_the_type_when_it_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/servers', $this->valid());
        $server = Server::query()->sole();

        $this->actingAs($admin)->put("/admin/servers/{$server->slug}", $this->valid(['server_type' => 'cax31', 'minecraft_version' => '1.21.11']))
            ->assertRedirect('/admin/servers');

        $server->refresh();
        $this->assertSame(21, $server->java_version);
        $this->assertSame('java -Xms13056M -Xmx13056M -jar server.jar nogui', $server->start_command);
    }

    public function test_a_version_its_source_doesnt_list_is_refused(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['minecraft_version' => '1.12.2']))
            ->assertSessionHasErrors(['minecraft_version' => 'Paper has no release 1.12.2. Pick a version from the list.']);
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['minecraft_version' => '']))
            ->assertSessionHasErrors(['minecraft_version' => 'Pick a Minecraft version.']);
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['minecraft_version' => '1.21; reboot']))
            ->assertSessionHasErrors('minecraft_version');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['software' => 'forge']))
            ->assertSessionHasErrors('software');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_an_unreachable_source_refuses_an_installed_server_but_not_a_custom_one(): void
    {
        $admin = User::factory()->admin()->create();
        $this->versions()->failWith("Couldn't reach PaperMC's version list: timeout");

        $this->actingAs($admin)->post('/admin/servers', $this->valid())
            ->assertSessionHasErrors(['minecraft_version' => "Couldn't reach PaperMC's version list: timeout Try again in a minute."]);

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['software' => 'custom', 'minecraft_version' => '26.3', 'start_command' => './run.sh', 'java_version' => '21']))
            ->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame(Software::Custom, $server->software);
        $this->assertNull($server->minecraft_version, 'A custom server keeps no Minecraft version.');
        $this->assertSame('./run.sh', $server->start_command);
        $this->assertSame(21, $server->java_version);
    }

    public function test_a_form_without_software_is_a_custom_server(): void
    {
        $admin = User::factory()->admin()->create();
        $data = $this->valid(['start_command' => './run.sh', 'java_version' => '17']);
        unset($data['software'], $data['minecraft_version']);

        $this->actingAs($admin)->post('/admin/servers', $data)->assertRedirect('/admin/servers');

        $this->assertSame(Software::Custom, Server::query()->sole()->software);
    }

    public function test_the_first_start_installs_paper_with_its_published_hash(): void
    {
        $server = Server::factory()->create(['slug' => 'paper', 'software' => Software::Paper, 'minecraft_version' => '26.3', 'java_version' => 25, 'start_command' => 'java -Xms6400M -Xmx6400M -jar server.jar nogui']);

        $run = $this->startAndBoot($server);

        $this->assertSame(RunStatus::Online, $run->status);
        $script = $this->prepareScript();
        $this->assertStringContainsString("echo 'Installing Paper 26.3 (build 1)'", $script);
        $this->assertStringContainsString("get 'https://fill-data.papermc.io/v1/objects/".FakeVersions::SHA256."/paper-26.3-1.jar' \"\$DIR\"/server.jar sha256 '".FakeVersions::SHA256."'", $script);
        $this->assertStringNotContainsString('fabric', $script);
        // The install comes before the start.
        $this->assertLessThan(strpos($script, 'systemctl start "$UNIT"'), strpos($script, 'get \'https://'));
    }

    public function test_fabric_runs_its_installer_on_top_of_the_vanilla_jar(): void
    {
        $server = Server::factory()->create(['slug' => 'fabric', 'software' => Software::Fabric, 'minecraft_version' => '1.21.11']);

        $this->startAndBoot($server);

        $script = $this->prepareScript();
        $this->assertStringContainsString("\"\$DIR\"/server.jar sha1 '".FakeVersions::SHA1."'", $script);
        $this->assertStringContainsString("\"\$M/work\"/fabric-installer.jar sha256 '".FakeVersions::SHA256."'", $script);
        $this->assertStringContainsString("java -jar \"\$M/work/fabric-installer.jar\" server -mcversion '1.21.11' -loader '0.20.1' -dir \"\$DIR\"", $script);
    }

    public function test_a_server_with_a_saved_world_is_never_installed_over(): void
    {
        $server = Server::factory()->create(['slug' => 'paper', 'software' => Software::Paper, 'minecraft_version' => '26.3']);
        $archives = $this->app->make(Archives::class);
        $path = $archives->newPath($server, 'upload.zip');
        $this->app->make(WorldStorage::class)->disk()->put($path, 'a saved world');
        $archives->record($server, $path, 13, hash('sha256', 'a saved world'), 'upload');

        $run = $this->startAndBoot($server->refresh());

        $this->assertSame(RunStatus::Online, $run->status);
        $script = $this->prepareScript();
        $this->assertStringContainsString('fetch "$P"', $script);
        $this->assertStringNotContainsString('Installing', $script);
    }

    public function test_a_custom_server_installs_nothing(): void
    {
        $this->startAndBoot(Server::factory()->create());

        $this->assertStringNotContainsString('Installing', $this->prepareScript());
    }

    public function test_an_unreachable_source_at_start_is_tried_again_on_the_next_check(): void
    {
        $server = Server::factory()->create(['slug' => 'paper', 'software' => Software::Paper, 'minecraft_version' => '26.3']);
        $this->versions()->failWith("Couldn't reach PaperMC's version list: timeout");

        $run = $this->startAndBoot($server);

        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNull($run->prepared_at);
        $this->assertNull($run->prepare_queued_at, 'Queued again on the next check.');
        $this->assertSame([], $this->shell()->commands(), 'Nothing ran on the VPS.');
        $this->assertSame(
            ["Couldn't look up the Paper 26.3 download yet, trying again: Couldn't reach PaperMC's version list: timeout"],
            ServerRunLog::query()->where('server_run_id', $run->id)->pluck('line')->all(),
        );

        $this->versions()->reset();
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(RunStatus::Online, $run->refresh()->status);
        $this->assertStringContainsString('Installing Paper 26.3', $this->prepareScript());
    }

    public function test_the_edit_page_shows_the_software(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'paper', 'software' => Software::Paper, 'minecraft_version' => '26.3']);

        $this->actingAs($admin)->get("/admin/servers/{$server->slug}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('server.software', 'paper')
                ->where('server.minecraft_version', '26.3')
                ->has('software.versions.fabric', 3));
    }
}
