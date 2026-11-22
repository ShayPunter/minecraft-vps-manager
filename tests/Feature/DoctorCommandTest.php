<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/mvm-doctor-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * A throwaway ed25519 pair, written like ssh-keygen would.
     */
    private function keyPair(string $name = 'id_ed25519'): string
    {
        $key = EC::createKey('Ed25519');
        file_put_contents("{$this->dir}/{$name}", $key->toString('OpenSSH'));
        file_put_contents("{$this->dir}/{$name}.pub", $key->getPublicKey()->toString('OpenSSH', ['comment' => 'mvm'])."\n");

        return "{$this->dir}/{$name}";
    }

    private function ready(): void
    {
        $private = $this->keyPair();
        config([
            'app.url' => 'https://minecraft.example.com',
            'services.hetzner.token' => 'test-token',
            'services.hetzner.ssh_private_key_path' => $private,
            'services.hetzner.ssh_public_key_path' => "{$private}.pub",
            'mail.default' => 'smtp',
        ]);
        User::factory()->admin()->create();
        Heartbeat::beat(Heartbeat::SCHEDULER);
    }

    public function test_a_ready_install_passes(): void
    {
        $this->ready();
        config(['queue.default' => 'database']);
        // A worker that runs it straight away: the doctor's test job lands at once.
        $this->app['config']->set('queue.connections.database', ['driver' => 'sync']);

        $this->artisan('app:doctor')
            ->expectsOutputToContain('Works (read-only call: 3 locations).')
            ->expectsOutputToContain('Private and public halves match.')
            ->expectsOutputToContain('Ran a test job on the database queue.')
            ->expectsOutputToContain('servers:watch ran')
            ->expectsOutputToContain('Ready to start servers.')
            ->assertSuccessful();
    }

    public function test_a_fresh_install_lists_what_is_missing(): void
    {
        config([
            'app.url' => 'http://minecraft.example.com',
            'services.hetzner.token' => '',
            'services.hetzner.ssh_private_key_path' => "{$this->dir}/missing",
            'services.hetzner.ssh_public_key_path' => "{$this->dir}/missing.pub",
            'mail.default' => 'log',
        ]);

        $this->artisan('app:doctor')
            ->expectsOutputToContain("isn't https")
            ->expectsOutputToContain('HETZNER_TOKEN is empty')
            ->expectsOutputToContain('ssh-keygen -t ed25519')
            ->expectsOutputToContain("servers:watch hasn't run yet.")
            ->expectsOutputToContain('No admin yet.')
            ->expectsOutputToContain('alert mails only reach the log')
            ->expectsOutputToContain('check(s) failed')
            ->assertFailed();
    }

    public function test_a_mismatched_key_pair_fails(): void
    {
        $this->ready();
        $other = $this->keyPair('other');
        config(['services.hetzner.ssh_public_key_path' => "{$other}.pub"]);

        $this->artisan('app:doctor')
            ->expectsOutputToContain("isn't the public half of")
            ->assertFailed();
    }

    public function test_the_public_key_given_as_the_private_one_fails(): void
    {
        $this->ready();
        config(['services.hetzner.ssh_private_key_path' => config('services.hetzner.ssh_public_key_path')]);

        $this->artisan('app:doctor')
            ->expectsOutputToContain('is a public key')
            ->assertFailed();
    }

    public function test_no_queue_worker_fails(): void
    {
        $this->ready();
        config(['queue.default' => 'database']);

        $this->artisan('app:doctor', ['--wait' => 0])
            ->expectsOutputToContain('No worker ran a test job on the database queue')
            ->assertFailed();
    }

    public function test_a_stale_scheduler_fails(): void
    {
        $this->ready();
        $this->travel(10)->minutes();

        $this->artisan('app:doctor')
            ->expectsOutputToContain('servers:watch last ran 10 minutes ago')
            ->assertFailed();
    }

    public function test_the_scheduled_commands_leave_heartbeats(): void
    {
        $this->artisan('servers:watch')->assertSuccessful();
        $this->artisan('servers:sweep')->assertSuccessful();

        $this->assertNotNull(Heartbeat::last(Heartbeat::SCHEDULER));
        // The sweep's test job runs at once on the sync queue.
        $this->assertNotNull(Heartbeat::last(Heartbeat::QUEUE));
    }

    public function test_it_never_prints_the_token(): void
    {
        $this->ready();
        config(['services.hetzner.token' => 'super-secret-token-value']);

        $this->artisan('app:doctor')
            ->doesntExpectOutputToContain('super-secret-token-value');
    }
}
