<?php

namespace Tests\Feature\Waker;

use App\Dns\DnsProvider;
use App\Dns\DnsRecord;
use App\Dns\FakeDnsProvider;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\User;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Wake on join's admin side (#5620): Admin → DNS's waker address, the server form's
 * "Wake on join", and app:doctor's waker check.
 */
class WakerAdminTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return array<string, mixed>
     */
    private function dnsForm(array $overrides = []): array
    {
        return ['domain' => 'example.com', 'zone_id' => self::ZONE, 'api_token' => 'cf_Token-123', 'waker_ipv4' => ' 198.51.100.10 ', ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    private function serverForm(array $overrides = []): array
    {
        return [
            'name' => 'All the Mods 9', 'slug' => 'atm9', 'server_type' => 'cx33', 'location' => 'fsn1',
            'start_command' => 'java -jar server.jar nogui', 'java_version' => 21, 'idle_minutes' => 15,
            'subdomain' => 'atm9',
            ...$overrides,
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function wakerRecords(): array
    {
        $fake = $this->app->make(DnsProvider::class);
        $this->assertInstanceOf(FakeDnsProvider::class, $fake);

        return array_values(array_map(
            fn (DnsRecord $r): array => [$r->name, $r->content],
            array_filter($fake->records(self::ZONE), fn (DnsRecord $r): bool => $r->wakerServerId() !== null),
        ));
    }

    public function test_the_waker_address_is_saved_shown_and_can_be_cleared(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/dns', $this->dnsForm())->assertSessionHasNoErrors();
        $this->assertSame('198.51.100.10', DnsSetting::query()->sole()->waker_ipv4);

        Heartbeat::beat(Heartbeat::WAKER);
        $this->actingAs($admin)->get('/admin/dns')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dns.waker_ipv4', '198.51.100.10')
            ->whereType('dns.waker_seen_at', 'string'));

        $this->actingAs($admin)->put('/admin/dns', $this->dnsForm(['waker_ipv4' => '']))->assertSessionHasNoErrors();
        $this->assertNull(DnsSetting::query()->sole()->waker_ipv4);
    }

    public function test_the_waker_address_must_be_a_public_ipv4_address(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['not an ip', '2001:db8::1', '192.168.1.20', '10.0.0.5', '127.0.0.1', '0.0.0.0'] as $address) {
            $this->actingAs($admin)->put('/admin/dns', $this->dnsForm(['waker_ipv4' => $address]))
                ->assertSessionHasErrors('waker_ipv4');
        }

        $this->assertSame(0, DnsSetting::query()->count());
    }

    public function test_wake_on_join_is_saved_needs_a_subdomain_and_parks_the_name(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/dns', $this->dnsForm())->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['wake_on_join' => '1', 'subdomain' => '']))
            ->assertSessionHasErrors('wake_on_join');

        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['wake_on_join' => '1']))->assertSessionHasNoErrors();
        $server = Server::query()->sole();
        $this->assertTrue($server->wake_on_join);
        $this->assertSame([['atm9.example.com', '198.51.100.10']], $this->wakerRecords());

        $this->actingAs($admin)->get('/admin/servers/atm9/edit')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('server.wake_on_join', true)
            ->where('wakerAddress', '198.51.100.10'));

        // Unticked (the box sends nothing): off, and the record goes.
        $this->actingAs($admin)->put('/admin/servers/atm9', $this->serverForm())->assertSessionHasNoErrors();
        $this->assertFalse($server->refresh()->wake_on_join);
        $this->assertSame([], $this->wakerRecords());
    }

    public function test_a_new_waker_address_moves_the_records_when_dns_is_saved(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/dns', $this->dnsForm())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['wake_on_join' => '1']))->assertSessionHasNoErrors();

        $this->actingAs($admin)->put('/admin/dns', $this->dnsForm(['waker_ipv4' => '198.51.100.20', 'api_token' => '']))->assertSessionHasNoErrors();

        $this->assertSame([['atm9.example.com', '198.51.100.20']], $this->wakerRecords());
        $this->actingAs($admin)->get('/admin/dns')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.0.waker', true));
    }

    public function test_players_cant_change_it(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)->put('/admin/dns', $this->dnsForm())->assertNotFound();
        $this->assertSame(0, DnsSetting::query()->count());
    }

    public function test_the_doctor_checks_the_waker_when_it_is_set_up(): void
    {
        $this->useFakeWorldStorage();
        config(['app.url' => 'https://minecraft.example.com', 'services.hetzner.token' => 'test-token', 'mail.default' => 'smtp']);
        config(['queue.default' => 'database']);
        $this->app['config']->set('queue.connections.database', ['driver' => 'sync']);
        User::factory()->admin()->create();
        Heartbeat::beat(Heartbeat::SCHEDULER);

        $this->artisan('app:doctor')->expectsOutputToContain('Not used (no waker address')->assertSuccessful();

        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = self::ZONE;
        $row->api_token = 'cf-token-123';
        $row->waker_ipv4 = '198.51.100.10';
        $row->save();
        Server::factory()->create(['subdomain' => 'atm9', 'wake_on_join' => true]);

        // Not running yet: a warning, never a failure.
        $this->artisan('app:doctor')->expectsOutputToContain("servers:waker hasn't run yet.")->assertSuccessful();

        Heartbeat::beat(Heartbeat::WAKER);
        $this->artisan('app:doctor')->expectsOutputToContain('servers:waker was listening')->assertSuccessful();
    }
}
