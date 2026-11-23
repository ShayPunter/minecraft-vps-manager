<?php

namespace Tests\Feature\Dns;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Dns\DnsProvider;
use App\Dns\DnsRecord;
use App\Dns\FakeDnsProvider;
use App\Enums\RunStatus;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Notifications\ServerAlert;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * A server's name follows its VPS (#5338): an A record while the run's VPS exists, deleted
 * when it ends, never in the way of a start or stop.
 */
class ServerDnsTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        Notification::fake();
        config(['minecraft.alert_mail' => 'ops@example.com']);
        $this->travelTo(now()->startOfMinute());
    }

    private function setUpDns(string $token = 'cf-token-123'): void
    {
        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = self::ZONE;
        $row->api_token = $token;
        $row->save();
    }

    private function dns(): FakeDnsProvider
    {
        $dns = $this->app->make(DnsProvider::class);
        $this->assertInstanceOf(FakeDnsProvider::class, $dns);

        return $dns;
    }

    private function cloud(): FakeCloudProvider
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);

        return $cloud;
    }

    private function launcher(): ServerLauncher
    {
        return $this->app->make(ServerLauncher::class);
    }

    private function start(string $slug = 'atm9', ?string $subdomain = 'atm9'): ServerRun
    {
        $server = Server::factory()->create(['name' => strtoupper($slug), 'slug' => $slug, 'subdomain' => $subdomain]);

        return $this->launcher()->start($server, User::factory()->create(), null)->refresh();
    }

    private function online(ServerRun $run): ServerRun
    {
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();
        $run->refresh();
        $this->assertSame(RunStatus::Online, $run->status);

        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);
        $shell->forget();

        return $run;
    }

    /**
     * @return list<DnsRecord>
     */
    private function records(): array
    {
        return $this->dns()->records(self::ZONE);
    }

    public function test_the_name_points_at_the_vps_while_it_runs_and_goes_when_it_stops(): void
    {
        $this->setUpDns();
        $run = $this->start();

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('A', $records[0]->type);
        $this->assertSame('atm9.example.com', $records[0]->name);
        $this->assertSame($run->ipv4, $records[0]->content);
        $this->assertSame(DnsRecord::commentForRun($run->id), $records[0]->comment);
        $this->assertSame($records[0]->id, $run->dns_record_id);
        $this->assertSame(self::ZONE, $run->dns_zone_id);
        $this->assertSame('atm9.example.com', $run->dns_name);
        $this->assertNull($run->dns_error);

        $run = $this->online($run);
        $shown = ServerPresenter::one($run->server->fresh(['activeRun', 'latestRun']));
        $this->assertSame('atm9.example.com', $shown['address']);
        $this->assertSame($run->ipv4, $shown['ip_address']);

        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));
        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertSame([], $this->records());
        $this->assertNull($run->dns_record_id);
        Notification::assertNothingSent();
    }

    public function test_a_server_without_a_subdomain_gets_no_record(): void
    {
        $this->setUpDns();
        $run = $this->online($this->start(subdomain: null));

        $this->assertSame([], $this->records());
        $this->assertNull($run->dns_error);
        $this->assertSame($run->ipv4, ServerPresenter::one($run->server->fresh(['activeRun', 'latestRun']))['address']);
    }

    public function test_without_dns_settings_the_server_still_starts_on_its_ip_and_admins_are_told_once(): void
    {
        $run = $this->online($this->start());

        $this->assertNull($run->dns_record_id);
        $this->assertStringContainsString("DNS isn't set up", (string) $run->dns_error);
        Notification::assertSentTimes(ServerAlert::class, 1);

        $server = $run->server->fresh(['activeRun', 'latestRun']);
        $this->assertSame($run->ipv4, ServerPresenter::one($server, User::factory()->admin()->create())['address']);
        $this->assertNotNull(ServerPresenter::one($server, User::factory()->admin()->create())['dns_error']);
        $this->assertNull(ServerPresenter::one($server, User::factory()->create())['dns_error'], 'Players never see it.');
        $this->assertNull(ServerPresenter::one($server)['dns_error']);
    }

    public function test_a_record_made_by_hand_is_never_touched(): void
    {
        $this->setUpDns();
        $mine = $this->dns()->seed(self::ZONE, 'CNAME', 'atm9.example.com', 'home.example.net');

        $run = $this->online($this->start());

        $this->assertNull($run->dns_record_id);
        $this->assertStringContainsString("already has a CNAME record that wasn't made by this app", (string) $run->dns_error);
        $this->assertEquals([$mine], $this->records());
        Notification::assertSentTimes(ServerAlert::class, 1);

        // The sweep leaves it alone too.
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->assertEquals([$mine], $this->records());
    }

    public function test_a_record_left_by_an_earlier_run_is_replaced(): void
    {
        $this->setUpDns();
        $this->dns()->seed(self::ZONE, 'A', 'atm9.example.com', '198.51.100.99', DnsRecord::commentForRun(9999));

        $run = $this->start();

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame($run->ipv4, $records[0]->content);
        $this->assertSame($run->id, $records[0]->runId());
    }

    public function test_a_cloudflare_error_never_stops_a_start_or_a_stop(): void
    {
        $this->setUpDns();
        $this->dns()->failWith('Cloudflare said: Internal error (code 1000)');

        $run = $this->online($this->start());
        $this->assertStringContainsString("Couldn't set atm9.example.com: Cloudflare said: Internal error", (string) $run->dns_error);
        Notification::assertSentTimes(ServerAlert::class, 1);

        // Cloudflare is back once the VPS is up, but the run's record was never made: nothing to delete.
        $this->dns()->failWith(null);
        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
    }

    public function test_a_record_cloudflare_wouldnt_delete_is_cleaned_up_by_the_sweep(): void
    {
        $this->setUpDns();
        $run = $this->online($this->start());
        $other = $this->online($this->start('vanilla', 'vanilla'));
        $this->assertCount(2, $this->records());

        $this->dns()->failWith('Cloudflare said: Internal error (code 1000)');
        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));
        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status, 'The stop finishes anyway.');
        $this->assertNull($run->dns_record_id);
        $this->assertCount(2, $this->records());

        $this->dns()->failWith(null);
        $this->dns()->seed(self::ZONE, 'TXT', '_mvm-test-abc.example.com', '"x"', DnsRecord::MANAGED.' test');
        $mine = $this->dns()->seed(self::ZONE, 'A', 'nas.example.com', '192.0.2.10', 'my NAS');
        $this->artisan('servers:sweep')->assertSuccessful();

        // Only the running server's record and the hand-made one are left.
        $left = $this->records();
        $this->assertCount(2, $left);
        $this->assertEqualsCanonicalizing([$other->dns_record_id, $mine->id], array_map(fn (DnsRecord $r) => $r->id, $left));
    }

    public function test_a_name_still_used_by_another_running_server_is_left_alone(): void
    {
        $this->setUpDns();
        $first = $this->start();
        // An admin moves the name to another server while the first still runs.
        $first->server->update(['subdomain' => null]);
        $second = $this->start('vanilla', 'atm9');

        $this->assertNull($second->dns_record_id);
        $this->assertStringContainsString('still points at ATM9, which is running', (string) $second->dns_error);
        $this->assertCount(1, $this->records());
        $this->assertSame($first->ipv4, $this->records()[0]->content);
    }
}
