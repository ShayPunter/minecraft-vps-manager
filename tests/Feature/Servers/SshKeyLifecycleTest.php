<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\CloudServer;
use App\Cloud\CloudSshKey;
use App\Cloud\FakeCloudProvider;
use App\Cloud\ServerSpec;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use App\Servers\SshKeyPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Every run makes its own SSH key pair (#5324): the private half lives (encrypted) on the
 * run, the public half is registered with the provider for that VPS only, and both go once
 * the VPS is gone.
 */
class SshKeyLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        Notification::fake();
        config(['minecraft.alert_mail' => 'ops@example.com']);
        $this->travelTo(now()->startOfMinute());
    }

    private function cloud(): FakeCloudProvider
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);

        return $cloud;
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    private function launcher(): ServerLauncher
    {
        return $this->app->make(ServerLauncher::class);
    }

    private function start(string $slug = 'vanilla'): ServerRun
    {
        $server = Server::factory()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->launcher()->start($server, User::factory()->create(), null)->refresh();
    }

    private function online(string $slug = 'vanilla'): ServerRun
    {
        $run = $this->start($slug);
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();
        $run->refresh();
        $this->assertSame(RunStatus::Online, $run->status);
        // The start ran prepare.sh (#5288); the tests below look at the stop.
        $this->shell()->forget();

        return $run;
    }

    public function test_a_generated_pair_is_ed25519_and_its_halves_match(): void
    {
        $pair = SshKeyPair::generate('mvm-vanilla-1');

        $this->assertMatchesRegularExpression('/^ssh-ed25519 [A-Za-z0-9+\/=]+ mvm-vanilla-1$/', $pair->publicKey);
        $private = PublicKeyLoader::load($pair->privateKey);
        $this->assertInstanceOf(PrivateKey::class, $private);
        $this->assertSame(
            explode(' ', $pair->publicKey)[1],
            explode(' ', trim($private->getPublicKey()->toString('OpenSSH')))[1],
        );
        $this->assertNotSame($pair->publicKey, SshKeyPair::generate('mvm-vanilla-1')->publicKey, 'A new pair every time.');
        $this->assertStringNotContainsString($pair->privateKey, print_r($pair, true));
    }

    public function test_a_start_registers_a_key_of_its_own_and_gives_the_vps_only_that_key(): void
    {
        $run = $this->start();
        $cloud = $this->cloud();

        $this->assertSame(RunStatus::Booting, $run->status);
        $this->assertNotNull($run->provider_ssh_key_id);
        $this->assertSame([$run->provider_ssh_key_id], $cloud->rawServer((int) $run->provider_server_id)['ssh_keys'] ?? null);

        $keys = $cloud->managedSshKeys();
        $this->assertCount(1, $keys);
        $this->assertSame("mvm-vanilla-{$run->id}", $keys[0]->name);
        $this->assertSame($run->id, $keys[0]->runId());

        // The registered public key is the half of the private key on the run.
        $private = PublicKeyLoader::load((string) $run->ssh_private_key);
        $this->assertInstanceOf(PrivateKey::class, $private);
        $this->assertSame(
            explode(' ', (string) $cloud->sshPublicKey((int) $run->provider_ssh_key_id))[1],
            explode(' ', trim($private->getPublicKey()->toString('OpenSSH')))[1],
        );

        // Two runs, two different keys.
        $other = $this->start('modded');
        $this->assertNotSame($run->provider_ssh_key_id, $other->provider_ssh_key_id);
        $this->assertNotSame($run->ssh_private_key, $other->ssh_private_key);
    }

    public function test_the_private_key_is_encrypted_at_rest_and_never_serialised(): void
    {
        $run = $this->start();
        $raw = (string) DB::table('server_runs')->where('id', $run->id)->value('ssh_private_key');

        $this->assertStringNotContainsString('OPENSSH PRIVATE KEY', $raw);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', (string) $run->ssh_private_key);
        $this->assertArrayNotHasKey('ssh_private_key', $run->toArray());
        $this->assertStringNotContainsString('PRIVATE KEY', $run->toJson());
    }

    public function test_the_stop_logs_in_with_the_runs_key_then_deletes_the_key(): void
    {
        $run = $this->online();
        $privateKey = $run->ssh_private_key;
        $keyId = (int) $run->provider_ssh_key_id;

        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertSame([$privateKey, $privateKey], $this->shell()->keys(), 'systemctl stop, then save.sh.');
        $this->assertNull($run->ssh_private_key);
        $this->assertNull($run->provider_ssh_key_id);
        $this->assertSame([], $this->cloud()->managedSshKeys());
        $this->assertNull($this->cloud()->sshPublicKey($keyId));
    }

    public function test_a_failed_stop_keeps_the_key_so_the_retry_can_log_in(): void
    {
        $run = $this->online();
        $privateKey = $run->ssh_private_key;
        $this->shell()->unreachable();

        $this->launcher()->requestStop($run, 'admin');

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertSame($privateKey, $run->ssh_private_key);
        $this->assertCount(1, $this->cloud()->managedSshKeys());

        // The sweep doesn't take the key of a run that still has its VPS.
        $this->shell()->succeed();
        $this->cloud()->failSshKeyDeletes();
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->cloud()->failSshKeyDeletes(false);

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status, 'The sweep retried the stop.');
        // The failed try, then the retry's stop and save.
        $this->assertSame([$privateKey, $privateKey, $privateKey], $this->shell()->keys());
        // The key delete failed during that retry: wiped on the run, left at the provider…
        $this->assertNull($run->ssh_private_key);
        $this->assertCount(1, $this->cloud()->managedSshKeys());

        // …until the next sweep, which finds it unused.
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->assertSame([], $this->cloud()->managedSshKeys());
    }

    public function test_a_start_the_provider_refuses_deletes_the_key(): void
    {
        $this->app->instance(CloudProvider::class, new class($this->app->make('cache.store')) extends FakeCloudProvider
        {
            public function createServer(ServerSpec $spec): CloudServer
            {
                throw new CloudException('resource_limit_exceeded', 'Hetzner: project limit exceeded', 403);
            }
        });

        $run = $this->start();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('project limit exceeded', (string) $run->error);
        $this->assertNull($run->ssh_private_key);
        $this->assertNull($run->provider_ssh_key_id);
        $this->assertSame([], $this->cloud()->managedSshKeys());
    }

    public function test_a_refused_key_fails_the_start_before_any_vps(): void
    {
        $this->app->instance(CloudProvider::class, new class($this->app->make('cache.store')) extends FakeCloudProvider
        {
            public function createSshKey(string $name, string $publicKey, array $labels = []): CloudSshKey
            {
                throw new CloudException('invalid_input', 'Hetzner: invalid input in field public_key', 422);
            }
        });

        $run = $this->start();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString("refused the VPS's SSH key", (string) $run->error);
        $this->assertNull($run->ssh_private_key);
        $this->assertSame([], $this->cloud()->managedServers());
    }

    public function test_a_boot_timeout_deletes_the_key_with_the_vps(): void
    {
        $run = $this->start();

        $this->travel(21)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($run->ssh_private_key);
        $this->assertSame([], $this->cloud()->managedServers());
        $this->assertSame([], $this->cloud()->managedSshKeys());
    }

    public function test_a_run_from_before_5324_stops_with_the_old_key_file(): void
    {
        $run = $this->online();
        // Started before the deploy: no key of its own.
        $run->ssh_private_key = null;
        $run->provider_ssh_key_id = null;
        $run->save();

        $this->launcher()->requestStop($run, 'admin');

        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertSame([null, null], $this->shell()->keys(), 'null = SshRemoteShell falls back to the old key file.');
    }

    public function test_the_sweep_deletes_unused_managed_keys_and_nothing_else(): void
    {
        $active = $this->online();
        $cloud = $this->cloud();

        // A key left behind by an ended run (the provider was down when it ended).
        $leftover = $cloud->createSshKey('mvm-old-3', 'ssh-ed25519 AAAA old', [CloudProvider::RUN_ID_LABEL => '999']);
        // A start whose key exists but whose id isn't saved on the run yet: kept.
        $server = Server::factory()->create(['slug' => 'other']);
        $provisioning = new ServerRun;
        $provisioning->server_id = $server->id;
        $provisioning->active_server_id = $server->id;
        $provisioning->status = RunStatus::Provisioning;
        $provisioning->save();
        $inFlight = $cloud->createSshKey("mvm-other-{$provisioning->id}", 'ssh-ed25519 AAAA new', [CloudProvider::RUN_ID_LABEL => (string) $provisioning->id]);
        // Shay's own key in the same Hetzner project: never touched.
        $handMade = $cloud->addUnmanagedSshKey('shay-laptop');

        $this->artisan('servers:sweep')->assertSuccessful();

        $left = array_map(fn (CloudSshKey $key) => $key->id, $cloud->managedSshKeys());
        sort($left);
        $this->assertSame([(int) $active->provider_ssh_key_id, $inFlight->id], $left);
        $this->assertNull($cloud->sshPublicKey($leftover->id));
        $this->assertNotNull($cloud->sshPublicKey($handMade->id));
    }
}
