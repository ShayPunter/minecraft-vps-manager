<?php

namespace Tests\Feature;

use App\Dns\FakeDnsProvider;
use App\Models\DnsSetting;
use App\Models\User;
use App\Models\WorldStorageSetting;
use App\Support\Heartbeat;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private function ready(): void
    {
        $this->useFakeWorldStorage();
        config([
            'app.url' => 'https://minecraft.example.com',
            'services.hetzner.token' => 'test-token',
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
            ->expectsOutputToContain('Made by the app for each VPS and deleted with it.')
            ->expectsOutputToContain('Ran a test job on the database queue.')
            ->expectsOutputToContain('servers:watch ran')
            ->expectsOutputToContain('SFTP minecraft@storage.example.com:22, base folder minecraft-worlds: connected and writable.')
            ->expectsOutputToContain('Ready to start servers.')
            ->assertSuccessful();
    }

    public function test_dns_is_optional_and_checked_when_set_up(): void
    {
        $this->ready();
        config(['queue.default' => 'database']);
        $this->app['config']->set('queue.connections.database', ['driver' => 'sync']);

        $this->artisan('app:doctor')
            ->expectsOutputToContain("DNS isn't set up")
            ->assertSuccessful();

        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $row->api_token = 'cf-doctor-secret';
        $row->save();

        $this->artisan('app:doctor')
            ->expectsOutputToContain("The token can edit example.com's DNS.")
            ->doesntExpectOutputToContain('cf-doctor-secret')
            ->assertSuccessful();

        $row->api_token = FakeDnsProvider::FAKE_BAD_TOKEN;
        $row->save();

        // A broken token is a warning: servers still start on their IP.
        $this->artisan('app:doctor')
            ->expectsOutputToContain('Cloudflare refused the token')
            ->assertSuccessful();
    }

    public function test_world_storage_that_cant_be_reached_fails(): void
    {
        $this->ready();
        WorldStorageSetting::query()->update(['host' => WorldStorage::FAKE_UNREACHABLE_HOST]);

        $this->artisan('app:doctor', ['--wait' => 0])
            ->expectsOutputToContain("Couldn't connect to unreachable.invalid:22")
            ->doesntExpectOutputToContain('hunter2-secret')
            ->assertFailed();
    }

    public function test_a_fresh_install_lists_what_is_missing(): void
    {
        config([
            'app.url' => 'http://minecraft.example.com',
            'services.hetzner.token' => '',
            'mail.default' => 'log',
        ]);

        $this->artisan('app:doctor')
            ->expectsOutputToContain("isn't https")
            ->expectsOutputToContain('HETZNER_TOKEN is empty')
            // No key to make by hand any more (#5324).
            ->doesntExpectOutputToContain('ssh-keygen')
            ->expectsOutputToContain("servers:watch hasn't run yet.")
            ->expectsOutputToContain('No admin yet.')
            ->expectsOutputToContain("Servers can't start until it works")
            ->expectsOutputToContain('alert mails only reach the log')
            ->expectsOutputToContain('check(s) failed')
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
