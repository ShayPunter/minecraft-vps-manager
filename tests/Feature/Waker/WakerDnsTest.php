<?php

namespace Tests\Feature\Waker;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\CloudServer;
use App\Cloud\FakeCloudProvider;
use App\Cloud\ServerSpec;
use App\Dns\Dns;
use App\Dns\DnsProvider;
use App\Dns\DnsRecord;
use App\Dns\FakeDnsProvider;
use App\Enums\RunStatus;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Wake on join's DNS side (#5620): while a server that wakes on join has no running VPS
 * holding its name, the name points at the waker; a start moves it to the VPS, the stop
 * moves it back, and the sweep mends the rest.
 */
class WakerDnsTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const WAKER = '198.51.100.10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
        config(['minecraft.alert_mail' => 'ops@example.com']);
    }

    private function setUpDns(?string $waker = self::WAKER): void
    {
        $row = DnsSetting::query()->first() ?? new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = self::ZONE;
        $row->api_token = 'cf-token-123';
        $row->waker_ipv4 = $waker;
        $row->save();
    }

    private function fake(): FakeDnsProvider
    {
        $dns = $this->app->make(DnsProvider::class);
        $this->assertInstanceOf(FakeDnsProvider::class, $dns);

        return $dns;
    }

    private function dns(): Dns
    {
        return $this->app->make(Dns::class);
    }

    private function server(string $slug = 'atm9', bool $wake = true): Server
    {
        return Server::factory()->create(['name' => strtoupper($slug), 'slug' => $slug, 'subdomain' => $slug, 'wake_on_join' => $wake]);
    }

    /**
     * @return list<array{string, string, string}> name, content, kind (waker / run / other)
     */
    private function records(): array
    {
        $rows = array_map(fn (DnsRecord $r): array => [
            $r->name, $r->content, match (true) {
                $r->wakerServerId() !== null => 'waker',
                $r->runId() !== null => 'run',
                default => 'other',
            },
        ], $this->fake()->records(self::ZONE));
        sort($rows);

        return $rows;
    }

    private function online(ServerRun $run): ServerRun
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);
        $cloud->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();
        $run->refresh();
        $this->assertSame(RunStatus::Online, $run->status);

        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);
        $shell->forget();

        return $run;
    }

    public function test_the_name_waits_at_the_waker_moves_to_the_vps_on_start_and_comes_back_on_stop(): void
    {
        $this->setUpDns();
        $server = $this->server();

        $this->dns()->syncParked($server);
        $this->assertSame([['atm9.example.com', self::WAKER, 'waker']], $this->records());
        $record = $this->fake()->records(self::ZONE)[0];
        $this->assertSame('A', $record->type);
        $this->assertSame(DnsRecord::commentForWaker($server->id), $record->comment);
        $this->assertSame($server->id, $record->wakerServerId());
        $this->assertNull($record->runId());

        $run = app(ServerLauncher::class)->start($server, User::factory()->create(), null)->refresh();
        $this->assertNotNull($run->ipv4);
        $this->assertSame([['atm9.example.com', $run->ipv4, 'run']], $this->records());
        $this->assertNull($run->dns_error);

        // The sweep leaves a running server's own record alone and makes no waker record.
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->assertSame([['atm9.example.com', $run->ipv4, 'run']], $this->records());

        $run = $this->online($run);
        $this->assertTrue(app(ServerLauncher::class)->requestStop($run, 'admin'));
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertSame([['atm9.example.com', self::WAKER, 'waker']], $this->records());
        Notification::assertNothingSent();
    }

    public function test_a_start_that_fails_before_the_vps_has_its_name_keeps_the_waker_record(): void
    {
        $this->setUpDns();
        $server = $this->server();
        $this->dns()->syncParked($server);

        $this->app->instance(CloudProvider::class, new class($this->app->make(Repository::class)) extends FakeCloudProvider
        {
            public function createServer(ServerSpec $spec): CloudServer
            {
                throw new CloudException('resource_limit_exceeded', 'server limit reached for this project');
            }
        });

        $run = app(ServerLauncher::class)->start($server, User::factory()->create(), null)->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame([['atm9.example.com', self::WAKER, 'waker']], $this->records());
    }

    public function test_servers_that_dont_wake_on_join_are_never_parked(): void
    {
        $this->setUpDns();
        $this->server('quiet', wake: false);
        $this->server('nameless')->forceFill(['subdomain' => null])->save();

        $this->artisan('servers:sweep')->assertSuccessful();

        $this->assertSame([], $this->records());
    }

    public function test_the_sweep_mends_waker_records(): void
    {
        $this->setUpDns();
        $atm9 = $this->server('atm9');
        $off = $this->server('off', wake: false);
        $gone = $this->server('gone');
        $fake = $this->fake();

        // Left over: an old waker address, a duplicate, a server that turned it off, one deleted.
        $fake->seed(self::ZONE, 'A', 'atm9.example.com', '198.51.100.1', DnsRecord::commentForWaker($atm9->id));
        $fake->seed(self::ZONE, 'A', 'atm9.example.com', self::WAKER, DnsRecord::commentForWaker($atm9->id));
        $fake->seed(self::ZONE, 'A', 'atm9.example.com', self::WAKER, DnsRecord::commentForWaker($atm9->id));
        $fake->seed(self::ZONE, 'A', 'off.example.com', self::WAKER, DnsRecord::commentForWaker($off->id));
        $fake->seed(self::ZONE, 'A', 'gone.example.com', self::WAKER, DnsRecord::commentForWaker($gone->id));
        $gone->delete();
        // A record made by hand elsewhere in the zone is never touched.
        $fake->seed(self::ZONE, 'A', 'www.example.com', '198.51.100.9');

        $this->artisan('servers:sweep')->assertSuccessful();

        $this->assertSame([
            ['atm9.example.com', self::WAKER, 'waker'],
            ['www.example.com', '198.51.100.9', 'other'],
        ], $this->records());
    }

    public function test_a_new_waker_address_or_subdomain_moves_the_record_and_no_address_removes_them(): void
    {
        $this->setUpDns();
        $server = $this->server();
        $this->dns()->syncParked();
        $this->assertSame([['atm9.example.com', self::WAKER, 'waker']], $this->records());

        $this->setUpDns('198.51.100.20');
        $this->dns()->syncParked();
        $this->assertSame([['atm9.example.com', '198.51.100.20', 'waker']], $this->records());

        $server->update(['subdomain' => 'mc.atm9']);
        $this->dns()->syncParked($server);
        $this->assertSame([['mc.atm9.example.com', '198.51.100.20', 'waker']], $this->records());

        $this->setUpDns(null);
        $this->dns()->syncParked();
        $this->assertSame([], $this->records());
    }

    public function test_a_record_made_by_hand_with_the_name_is_left_alone(): void
    {
        $this->setUpDns();
        $this->server();
        $this->fake()->seed(self::ZONE, 'CNAME', 'atm9.example.com', 'elsewhere.example.net');

        $this->dns()->syncParked();

        $this->assertSame([['atm9.example.com', 'elsewhere.example.net', 'other']], $this->records());
    }

    public function test_a_record_from_an_ended_run_is_replaced(): void
    {
        $this->setUpDns();
        $server = $this->server();
        $this->fake()->seed(self::ZONE, 'A', 'atm9.example.com', '198.51.100.3', DnsRecord::commentForRun(999));

        $this->dns()->syncParked();

        $this->assertSame([['atm9.example.com', self::WAKER, 'waker']], $this->records());
        $this->assertTrue($this->dns()->shouldPark($server));
    }

    public function test_cloudflare_errors_never_get_in_the_way(): void
    {
        $this->setUpDns();
        $server = $this->server();
        $this->fake()->failWith('Cloudflare is down');

        $this->dns()->syncParked();
        $this->artisan('servers:sweep')->assertSuccessful();

        $run = app(ServerLauncher::class)->start($server, User::factory()->create(), null)->refresh();
        $this->assertSame(RunStatus::Booting, $run->status);

        $this->fake()->failWith(null);
        $this->assertSame([], $this->records());
    }
}
