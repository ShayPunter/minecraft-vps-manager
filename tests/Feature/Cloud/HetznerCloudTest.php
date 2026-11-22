<?php

namespace Tests\Feature\Cloud;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Cloud\HetznerCloud;
use App\Cloud\ServerSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Hetzner client against Http::fake(), with response shapes from
 * https://docs.hetzner.cloud/reference/cloud. Nothing here reaches the real API.
 */
class HetznerCloudTest extends TestCase
{
    private const API = 'https://api.hetzner.cloud/v1';

    /** MD5 fingerprint of tests/fixtures/ssh/id_ed25519.pub, from `ssh-keygen -l -E md5`. */
    private const FIXTURE_FINGERPRINT = '25:60:59:d7:45:23:5b:cb:d9:26:46:bd:94:e5:1d:3c';

    private function client(?string $token = 'test-token', ?string $keyPath = null): HetznerCloud
    {
        return new HetznerCloud($token, self::API, $keyPath ?? base_path('tests/fixtures/ssh/id_ed25519.pub'));
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function serverJson(int $id, string $status = 'initializing', array $labels = ['managed-by' => 'minecraft-vps-manager', 'mvm-server-id' => '7']): array
    {
        return [
            'id' => $id,
            'name' => "mvm-{$id}",
            'status' => $status,
            'created' => '2026-11-22T15:00:00+00:00',
            'public_net' => [
                'ipv4' => ['id' => 5, 'ip' => '203.0.113.10', 'blocked' => false, 'dns_ptr' => 'static.example'],
                'ipv6' => ['id' => 6, 'ip' => '2001:db8::/64', 'blocked' => false, 'dns_ptr' => []],
                'floating_ips' => [],
                'firewalls' => [],
            ],
            'server_type' => ['name' => 'cx43'],
            'labels' => $labels,
        ];
    }

    private function noKeysYet(): array
    {
        return ['ssh_keys' => [], 'meta' => ['pagination' => ['page' => 1, 'per_page' => 25, 'previous_page' => null, 'next_page' => null, 'last_page' => 1, 'total_entries' => 0]]];
    }

    public function test_tests_get_the_fake_whatever_the_config_says(): void
    {
        config(['services.hetzner.fake' => false, 'services.hetzner.token' => 'real-looking-token']);
        $this->app->forgetInstance(CloudProvider::class);

        $this->assertInstanceOf(FakeCloudProvider::class, $this->app->make(CloudProvider::class));
    }

    public function test_the_e2e_environment_gets_the_fake_too(): void
    {
        config(['services.hetzner.fake' => false, 'services.hetzner.token' => 'real-looking-token']);
        $this->app['env'] = 'e2e';
        $this->app->forgetInstance(CloudProvider::class);

        $this->assertInstanceOf(FakeCloudProvider::class, $this->app->make(CloudProvider::class));

        $this->app['env'] = 'production';
        $this->app->forgetInstance(CloudProvider::class);
        $this->assertInstanceOf(HetznerCloud::class, $this->app->make(CloudProvider::class));

        $this->app['env'] = 'testing';
    }

    public function test_the_fingerprint_matches_ssh_keygen(): void
    {
        $key = (string) file_get_contents(base_path('tests/fixtures/ssh/id_ed25519.pub'));

        $this->assertSame(self::FIXTURE_FINGERPRINT, HetznerCloud::fingerprint($key));
    }

    public function test_creating_a_server_registers_the_ssh_key_and_labels_the_server(): void
    {
        Http::fake([
            self::API.'/ssh_keys?*' => Http::response($this->noKeysYet()),
            self::API.'/ssh_keys' => Http::response(['ssh_key' => ['id' => 42, 'name' => 'minecraft-vps-manager', 'fingerprint' => self::FIXTURE_FINGERPRINT]], 201),
            self::API.'/servers' => Http::response([
                'server' => $this->serverJson(4711),
                'action' => ['id' => 13, 'command' => 'create_server', 'status' => 'running'],
                'next_actions' => [],
                'root_password' => null,
            ], 201),
        ]);

        $server = $this->client()->createServer(new ServerSpec(
            name: 'mvm-atm9',
            serverType: 'cx43',
            location: 'fsn1',
            minecraftServerId: 7,
            userData: "#cloud-config\nruncmd: []\n",
            labels: ['managed-by' => 'someone-else', 'pack' => 'atm9'],
        ));

        $this->assertSame(4711, $server->id);
        $this->assertSame('initializing', $server->status);
        $this->assertSame('203.0.113.10', $server->ipv4);
        $this->assertTrue($server->isManaged());
        $this->assertSame(7, $server->minecraftServerId());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), self::API.'/ssh_keys?')
            && $r['fingerprint'] === self::FIXTURE_FINGERPRINT
            && $r->hasHeader('Authorization', 'Bearer test-token'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::API.'/ssh_keys'
            && str_starts_with((string) $r['public_key'], 'ssh-ed25519 ')
            && $r['name'] === 'minecraft-vps-manager');

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST' || $r->url() !== self::API.'/servers') {
                return false;
            }

            $this->assertSame('mvm-atm9', $r['name']);
            $this->assertSame('cx43', $r['server_type']);
            $this->assertSame('fsn1', $r['location']);
            $this->assertSame('ubuntu-24.04', $r['image']);
            $this->assertSame([42], $r['ssh_keys']);
            $this->assertSame("#cloud-config\nruncmd: []\n", $r['user_data']);
            // The app's own labels win over the extras.
            $this->assertSame(['managed-by' => 'minecraft-vps-manager', 'pack' => 'atm9', 'mvm-server-id' => '7'], $r['labels']);
            $this->assertArrayNotHasKey('root_password', $r->data());

            return true;
        });
    }

    public function test_an_already_registered_key_is_reused(): void
    {
        Http::fake([
            self::API.'/ssh_keys?*' => Http::response(['ssh_keys' => [['id' => 99, 'name' => 'minecraft-vps-manager', 'fingerprint' => self::FIXTURE_FINGERPRINT]], 'meta' => []]),
            self::API.'/servers' => Http::response(['server' => $this->serverJson(1), 'action' => []], 201),
        ]);

        $client = $this->client();
        $client->createServer(new ServerSpec('a', 'cx43', 'fsn1', 1));
        $client->createServer(new ServerSpec('b', 'cx43', 'fsn1', 2));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API.'/ssh_keys');
        // Looked up once, then remembered.
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/servers' && $r['ssh_keys'] === [99]);
    }

    public function test_a_missing_ssh_key_is_refused_before_any_request(): void
    {
        Http::fake();

        try {
            $this->client(keyPath: base_path('tests/fixtures/ssh/missing.pub'))->createServer(new ServerSpec('a', 'cx43', 'fsn1', 1));
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_a_missing_token_is_refused_before_any_request(): void
    {
        Http::fake();

        try {
            $this->client(token: '')->locations();
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_hetzner_errors_become_cloud_exceptions_with_the_code(): void
    {
        Http::fake([
            self::API.'/ssh_keys?*' => Http::response(['ssh_keys' => [['id' => 99]], 'meta' => []]),
            self::API.'/servers' => Http::response(['error' => [
                'code' => 'resource_limit_exceeded',
                'message' => 'project limit exceeded',
                'details' => ['limits' => [['name' => 'project_limit']]],
            ]], 403),
        ]);

        try {
            $this->client()->createServer(new ServerSpec('a', 'cx43', 'fsn1', 1));
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('resource_limit_exceeded', $e->errorCode);
            $this->assertSame(403, $e->status);
            $this->assertStringContainsString('project limit exceeded', $e->getMessage());
        }
    }

    public function test_an_error_without_a_hetzner_body_still_has_a_code(): void
    {
        Http::fake([self::API.'/locations?*' => Http::response('Bad Gateway', 502)]);

        try {
            $this->client()->locations();
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('unexpected_response', $e->errorCode);
            $this->assertSame(502, $e->status);
        }
    }

    public function test_find_server_returns_null_when_it_is_gone(): void
    {
        Http::fake([
            self::API.'/servers/5' => Http::response($this->serverJsonResponse(5, 'running')),
            self::API.'/servers/6' => Http::response(['error' => ['code' => 'not_found', 'message' => 'server not found']], 404),
        ]);

        $client = $this->client();
        $this->assertSame('running', $client->findServer(5)?->status);
        $this->assertTrue($client->findServer(5)?->isRunning());
        $this->assertNull($client->findServer(6));
    }

    public function test_only_managed_servers_are_deleted(): void
    {
        Http::fake([
            self::API.'/servers/5' => Http::sequence()
                ->push($this->serverJsonResponse(5, 'running'))
                ->push(null, 204),
            self::API.'/servers/8' => Http::response($this->serverJsonResponse(8, 'running', ['owner' => 'shay'])),
        ]);

        $client = $this->client();
        $client->deleteServer(5);

        try {
            $client->deleteServer(8);
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('not_managed', $e->errorCode);
        }

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/servers/5');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/servers/8');
    }

    public function test_deleting_a_server_that_is_already_gone_is_fine(): void
    {
        Http::fake([self::API.'/servers/5' => Http::response(['error' => ['code' => 'not_found', 'message' => 'not found']], 404)]);

        $this->client()->deleteServer(5);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_managed_servers_use_the_label_selector_and_follow_pages(): void
    {
        Http::fake([
            self::API.'/servers?*' => Http::sequence()
                ->push(['servers' => [$this->serverJson(1, 'running')], 'meta' => ['pagination' => ['page' => 1, 'next_page' => 2, 'last_page' => 2]]])
                ->push(['servers' => [$this->serverJson(2, 'off'), $this->serverJson(3, 'running', [])], 'meta' => ['pagination' => ['page' => 2, 'next_page' => null, 'last_page' => 2]]]),
        ]);

        $servers = $this->client()->managedServers();

        // Server 3 has no label: never handed back, even if the API did.
        $this->assertSame([1, 2], array_map(fn ($s) => $s->id, $servers));
        Http::assertSent(fn (Request $r) => $r['label_selector'] === 'managed-by=minecraft-vps-manager' && (int) $r['page'] === 1);
        Http::assertSent(fn (Request $r) => (int) $r['page'] === 2);
    }

    public function test_server_types_and_locations_are_mapped(): void
    {
        Http::fake([
            self::API.'/server_types?*' => Http::response([
                'server_types' => [
                    [
                        'id' => 1, 'name' => 'cx43', 'description' => 'CX43', 'cores' => 8, 'memory' => 16.0, 'disk' => 160,
                        'deprecated' => false, 'deprecation' => null, 'storage_type' => 'local', 'cpu_type' => 'shared', 'architecture' => 'x86',
                        'prices' => [
                            ['location' => 'fsn1', 'price_hourly' => ['net' => '0.0160', 'gross' => '0.0190'], 'price_monthly' => ['net' => '9.9900', 'gross' => '11.8881'], 'included_traffic' => 21990232555520, 'price_per_tb_traffic' => ['net' => '1.0000', 'gross' => '1.1900']],
                        ],
                    ],
                    [
                        'id' => 2, 'name' => 'cx11', 'description' => 'CX11', 'cores' => 1, 'memory' => 2, 'disk' => 20,
                        'deprecated' => true, 'deprecation' => ['unavailable_after' => '2024-09-01T00:00:00+00:00', 'announced' => '2024-06-01T00:00:00+00:00'],
                        'cpu_type' => 'shared', 'architecture' => 'x86', 'prices' => [],
                    ],
                ],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null]],
            ]),
            self::API.'/locations?*' => Http::response([
                'locations' => [['id' => 1, 'name' => 'fsn1', 'description' => 'Falkenstein DC Park 1', 'country' => 'DE', 'city' => 'Falkenstein', 'latitude' => 50.47612, 'longitude' => 12.370071, 'network_zone' => 'eu-central']],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null]],
            ]),
        ]);

        $client = $this->client();
        [$cx43, $cx11] = $client->serverTypes();

        $this->assertSame('cx43', $cx43->name);
        $this->assertSame(16.0, $cx43->memoryGb);
        $this->assertSame(['fsn1' => '11.8881'], $cx43->monthlyGrossPrices);
        $this->assertTrue($cx43->availableIn('fsn1'));
        $this->assertFalse($cx43->availableIn('hel1'));
        $this->assertFalse($cx43->deprecated);
        $this->assertTrue($cx11->deprecated);

        $locations = $client->locations();
        $this->assertSame('fsn1', $locations[0]->name);
        $this->assertSame('Falkenstein', $locations[0]->city);
        $this->assertSame('DE', $locations[0]->country);
    }

    /**
     * @param  array<string, string>|null  $labels
     * @return array<string, mixed>
     */
    private function serverJsonResponse(int $id, string $status, ?array $labels = null): array
    {
        return ['server' => $labels === null ? $this->serverJson($id, $status) : $this->serverJson($id, $status, $labels)];
    }
}
