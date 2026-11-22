<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\ServerRunLog;
use App\Models\User;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\RunLog;
use App\Servers\ServerLauncher;
use App\Servers\WorldSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Run logs (#5367): the VPS's shipper posts its log with the run's own token, the app adds
 * prepare.sh's output and, before deleting a VPS that never came online, its last journal
 * lines, and says what probably went wrong.
 */
class RunLogTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
        config(['app.url' => 'https://minecraft.example.com']);
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    private function server(array $attributes = []): Server
    {
        return Server::query()->where('slug', 'vanilla')->first()
            ?? Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla', ...$attributes]);
    }

    private function startAndBoot(?Server $server = null): ServerRun
    {
        $run = $this->app->make(ServerLauncher::class)->start($server ?? $this->server(), null, null)->refresh();
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
        return collect($this->shell()->files())->mapWithKeys(fn (array $f) => [$f['path'] => $f['contents']])->all();
    }

    private function otherRun(?string $tokenHash = null): ServerRun
    {
        $run = new ServerRun;
        $run->server_id = Server::factory()->create()->id;
        $run->status = RunStatus::Failed;
        $run->log_token_hash = $tokenHash;
        $run->save();

        return $run;
    }

    private function token(): string
    {
        preg_match('/Authorization: Bearer ([A-Za-z0-9]+)"/', $this->files()['/root/.mvm-log/curl.conf'], $m);

        return $m[1];
    }

    private function ship(ServerRun $run, string $body, ?string $token, array $headers = []): TestResponse
    {
        return $this->call('POST', "/runs/{$run->id}/log", [], [], [], [
            ...($token !== null ? ['HTTP_AUTHORIZATION' => "Bearer {$token}"] : []),
            'CONTENT_TYPE' => 'text/plain',
            ...$headers,
        ], $body);
    }

    public function test_the_vps_gets_a_shipper_with_its_own_token(): void
    {
        $run = $this->startAndBoot();
        $files = collect($this->shell()->files())->keyBy('path');

        $this->assertSame(0700, $files['/root/.mvm-log/ship.sh']['mode']);
        $this->assertSame(0600, $files['/root/.mvm-log/curl.conf']['mode']);
        $this->assertStringContainsString("url = \"https://minecraft.example.com/runs/{$run->id}/log\"", $files['/root/.mvm-log/curl.conf']['contents']);
        $this->assertStringContainsString("UNIT='minecraft-vanilla.service'", $files['/root/.mvm-log/ship.sh']['contents']);
        $this->assertStringContainsString('ExecStart=/bin/sh /root/.mvm-log/ship.sh', $files['/root/.mvm-log/mvm-log-ship.service']['contents']);

        // Only the hash is kept, and it matches the token the VPS got.
        $this->assertSame(hash('sha256', $this->token()), $run->log_token_hash);
        $this->assertStringNotContainsString($this->token(), json_encode($run->toArray(), JSON_THROW_ON_ERROR));

        // The token is never on a command line.
        foreach ($this->shell()->commands() as $command) {
            $this->assertStringNotContainsString($this->token(), $command['command']);
        }

        // prepare.sh's own output is the first thing in the log.
        $this->assertSame([['prepare', 'prepared']], ServerRunLog::query()->get()->map(fn ($l) => [$l->source, $l->line])->all());
    }

    public function test_the_shipper_posts_lines_with_the_token(): void
    {
        $run = $this->startAndBoot();
        $token = $this->token();

        $this->ship($run, "2026-11-22T21:00:00+0000 vps java[1]: [21:00:00 INFO]: Starting minecraft server version 26.3\r\n\n2026-11-22T21:00:05+0000 vps java[1]: [21:00:05 INFO]: Done (4.2s)!\n", $token)
            ->assertNoContent();
        $this->ship($run, "Cloud-init v. 24.4 finished\n", $token, ['HTTP_X_MVM_SOURCE' => 'setup'])->assertNoContent();

        $lines = ServerRunLog::query()->where('server_run_id', $run->id)->orderBy('id')->get();
        $this->assertSame(['prepare', 'minecraft', 'minecraft', 'setup'], $lines->pluck('source')->all());
        $this->assertSame('2026-11-22T21:00:00+0000 vps java[1]: [21:00:00 INFO]: Starting minecraft server version 26.3', $lines[1]->line, 'CR stripped, empty lines skipped.');
    }

    public function test_a_wrong_token_or_an_unknown_run_gets_the_same_404(): void
    {
        $run = $this->startAndBoot();

        $this->ship($run, "x\n", 'not-the-token-'.str_repeat('a', 40))->assertNotFound();
        $this->ship($run, "x\n", null)->assertNotFound();
        $this->call('POST', '/runs/999999/log', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token()], "x\n")->assertNotFound();

        // Another run's token doesn't fit this run.
        $other = $this->otherRun(hash('sha256', 'other-token-'.str_repeat('b', 40)));
        $this->ship($run, "x\n", 'other-token-'.str_repeat('b', 40))->assertNotFound();
        $this->ship($other, "x\n", $this->token())->assertNotFound();

        $this->assertSame(1, ServerRunLog::query()->count(), 'Only prepare.sh\'s line.');
    }

    public function test_an_ended_run_tells_the_shipper_to_stop(): void
    {
        $run = $this->startAndBoot();
        $token = $this->token();
        $this->app->make(ServerLauncher::class)->requestStop($run, 'admin');
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);

        $this->ship($run, "late line\n", $token)->assertStatus(410);
    }

    public function test_a_batch_too_big_is_refused_and_the_log_is_capped(): void
    {
        $run = $this->startAndBoot();
        $token = $this->token();

        $this->ship($run, str_repeat('x', RunLog::MAX_BATCH_BYTES + 1), $token)->assertStatus(413);

        $log = $this->app->make(RunLog::class);

        foreach (range(1, 3) as $batch) {
            $log->append($run, 'minecraft', implode("\n", array_map(fn ($i) => "batch {$batch} line {$i}", range(1, 2000))));
        }

        $lines = ServerRunLog::query()->where('server_run_id', $run->id)->orderBy('id');
        $this->assertSame(RunLog::MAX_LINES, $lines->count());
        $this->assertSame('batch 3 line 2000', $lines->get()->last()->line, 'The newest are kept.');

        $log->append($run, 'minecraft', str_repeat('y', 5000));
        $this->assertSame(RunLog::MAX_LINE_LENGTH, mb_strlen((string) ServerRunLog::query()->latest('id')->value('line')));
    }

    public function test_the_intake_sets_no_cookie(): void
    {
        $run = $this->startAndBoot();

        $response = $this->ship($run, "x\n", $this->token());

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_start_that_never_comes_online_keeps_its_log_and_says_why(): void
    {
        $server = $this->server(['name' => 'Paper']);
        $this->shell()->failMatching('journalctl -u minecraft-vanilla.service -n 200', 0,
            "2026-11-22T21:00:01+0000 vps java[1]: [21:00:01 WARN]: Failed to load eula.txt\n"
            ."2026-11-22T21:00:01+0000 vps java[1]: [21:00:01 INFO]: You need to agree to the EULA in order to run the server. Go to eula.txt for more info.\n"
            ."--- systemctl status ---\n   Active: inactive (dead)\n");
        $this->app->make(MinecraftProbe::class)->refuse();
        $run = $this->startAndBoot($server);
        $vpsId = (int) $run->provider_server_id;

        $this->travel(21)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($this->app->make(CloudProvider::class)->findServer($vpsId), 'The VPS is still deleted.');
        $this->assertStringContainsString("wasn't online within 20 minutes", (string) $run->error);
        $this->assertStringContainsString('Likely cause: Minecraft stopped because the EULA wasn\'t accepted: tick "I accept the Minecraft EULA"', (string) $run->error);
        $this->assertTrue(ServerRunLog::query()->where('server_run_id', $run->id)->where('source', 'fallback')
            ->where('line', 'like', '%You need to agree to the EULA%')->exists());

        // The command pulled was the fallback, with the run's own key.
        $commands = array_column($this->shell()->commands(), 'command');
        $this->assertSame(RunLog::fallbackCommand($server), end($commands));
    }

    public function test_a_vps_that_cant_be_reached_is_still_deleted(): void
    {
        $this->app->make(MinecraftProbe::class)->refuse();
        $run = $this->startAndBoot();
        $vpsId = (int) $run->provider_server_id;
        $this->shell()->unreachable('Connection refused');

        $this->travel(21)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertNull($this->app->make(CloudProvider::class)->findServer($vpsId));
        $this->assertStringNotContainsString('Likely cause', (string) $run->error);
    }

    public function test_a_failed_setup_keeps_prepares_output(): void
    {
        $this->shell()->failMatching(WorldSync::PREPARE_COMMAND, 20, "java isn't installed: the VPS's first-boot setup (cloud-init) didn't finish.\n");

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Likely cause: Java didn\'t install on the VPS', (string) $run->error);
        $this->assertTrue(ServerRunLog::query()->where('server_run_id', $run->id)->where('source', 'prepare')->exists());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function causes(): array
    {
        return [
            'java too old' => [
                'Exception in thread "main" java.lang.UnsupportedClassVersionError: io/papermc/paperclip/Main has been compiled by a more recent version of the Java Runtime (class file version 69.0), this version of the Java Runtime only recognizes class file versions up to 65.0',
                'The server jar needs Java 25, but the VPS has Java 21: set the server\'s Java version to 25.',
            ],
            'memory' => ['Error occurred during initialization of VM'."\n".'Could not reserve enough space for 5242880KB object heap', 'Java couldn\'t get the memory'],
            'missing jar' => ['Error: Unable to access jarfile paper.jar', 'The start command\'s jar (paper.jar) isn\'t in the server folder'],
            'not executable' => ['mvm-start: 4: exec: ./run.sh: Permission denied', 'The start command couldn\'t run (Permission denied). For a script, use "sh run.sh"'],
            'port' => ['[21:00:00 WARN]: **** FAILED TO BIND TO PORT!', 'Minecraft couldn\'t open its port'],
        ];
    }

    #[DataProvider('causes')]
    public function test_the_usual_causes_are_named(string $log, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) RunLog::explain($log));
    }

    public function test_only_the_latest_start_is_diagnosed(): void
    {
        $run = $this->startAndBoot();
        $this->app->make(RunLog::class)->append($run, 'minecraft', implode("\n", [
            '2026-11-22T21:30:34+00:00 vps systemd[1]: Started minecraft-vanilla.service - Minecraft server vanilla.',
            '2026-11-22T21:30:34+00:00 vps mvm-start[219]: [21:00:01 INFO]: You need to agree to the EULA in order to run the server.',
            '2026-11-22T21:31:06+00:00 vps systemd[1]: Started minecraft-vanilla.service - Minecraft server vanilla.',
            '2026-11-22T21:31:06+00:00 vps mvm-start[367]: Error: Unable to access jarfile paper.jar',
        ]));

        $this->assertStringStartsWith("The start command's jar (paper.jar)", (string) $this->app->make(RunLog::class)->diagnose($run));
    }

    public function test_an_ordinary_log_names_no_cause(): void
    {
        $this->assertNull(RunLog::explain("[21:00:00 INFO]: Starting minecraft server version 26.3\n[21:00:05 INFO]: Done (4.2s)!"));
    }

    public function test_admins_see_the_log_page_and_players_dont(): void
    {
        $run = $this->startAndBoot();
        $this->ship($run, "[21:00:05 INFO]: Done (4.2s)!\n", $this->token());
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->actingAs($player)->get('/admin/servers/vanilla/log')->assertNotFound();
        $this->actingAs($player)->get('/admin/servers/vanilla/log/lines?run='.$run->id)->assertNotFound();

        $this->actingAs($admin)->get('/admin/servers/vanilla/log')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/servers/Log')
                ->where('run.id', $run->id)
                ->where('run.active', true)
                ->has('lines', 2)
                ->where('lines.1.line', '[21:00:05 INFO]: Done (4.2s)!')
                ->where('lines.1.source', 'minecraft'));

        $first = (int) ServerRunLog::query()->orderBy('id')->value('id');
        $this->actingAs($admin)->getJson("/admin/servers/vanilla/log/lines?run={$run->id}&after={$first}")
            ->assertOk()
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('active', true);

        // A run of another server isn't shown here.
        $other = $this->otherRun();
        $this->actingAs($admin)->get("/admin/servers/vanilla/log?run={$other->id}")->assertNotFound();
    }

    public function test_a_server_never_started_has_an_empty_log_page(): void
    {
        $this->server();

        $this->actingAs(User::factory()->admin()->create())->get('/admin/servers/vanilla/log')
            ->assertInertia(fn (Assert $page) => $page->where('run', null)->has('lines', 0));
    }

    public function test_old_lines_are_pruned(): void
    {
        $run = $this->startAndBoot();
        $this->travel(15)->days();
        $this->ship($run, "fresh\n", $this->token());

        $this->artisan('servers:prune-logs')->assertSuccessful();

        $this->assertSame(['fresh'], ServerRunLog::query()->pluck('line')->all());
    }

    public function test_the_eula_box_is_saved_with_who_and_when_and_writes_eula_txt(): void
    {
        $admin = User::factory()->admin()->create();
        $server = $this->server();
        $form = fn (array $overrides) => [
            ...$server->only(['name', 'slug', 'server_type', 'location', 'start_command', 'java_version', 'idle_minutes']),
            ...$overrides,
        ];

        $this->actingAs($admin)->put('/admin/servers/vanilla', $form(['eula' => '1']))->assertSessionHasNoErrors();
        $server->refresh();
        $this->assertNotNull($server->eula_accepted_at);
        $this->assertSame($admin->id, $server->eula_accepted_by);

        $this->actingAs($admin)->get('/admin/servers/vanilla/edit')
            ->assertInertia(fn (Assert $page) => $page->where('server.eula_accepted_by', $admin->name));

        $this->startAndBoot($server);
        $this->assertStringContainsString("'eula=true' > \"\$DIR/eula.txt\"", $this->files()['/root/.mvm/prepare.sh']);

        // Unticked: cleared, and no eula.txt is written.
        $this->app->make(ServerLauncher::class)->requestStop($server->activeRun()->firstOrFail(), 'admin');
        $this->actingAs($admin)->put('/admin/servers/vanilla', $form([]))->assertSessionHasNoErrors();
        $this->assertNull($server->refresh()->eula_accepted_at);
        $this->assertNull($server->eula_accepted_by);
        $this->shell()->forget();
        $this->startAndBoot($server);
        $this->assertStringNotContainsString('eula.txt', $this->files()['/root/.mvm/prepare.sh']);
    }

    public function test_prepare_makes_top_level_scripts_executable(): void
    {
        $this->startAndBoot();

        $this->assertStringContainsString("find \"\$DIR\" -maxdepth 1 -type f -name '*.sh' -exec chmod 0755 {} +", $this->files()['/root/.mvm/prepare.sh']);
        $this->assertStringContainsString('systemctl restart mvm-log-ship.service', $this->files()['/root/.mvm/prepare.sh']);
    }
}
