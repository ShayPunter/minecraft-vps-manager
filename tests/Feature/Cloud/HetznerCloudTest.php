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

    private function client(?string $token = 'test-token'): HetznerCloud
    {
        return new HetznerCloud($token, self::API);
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function sshKeyJson(int $id, array $labels = ['managed-by' => 'minecraft-vps-manager', 'mvm-run-id' => '12']): array
    {
        return [
            'id' => $id,
            'name' => "mvm-atm9-{$id}",
            'fingerprint' => 'b7:2f:30:a0:2f:6c:58:6c:21:04:58:61:ba:06:3b:2f',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGmq mvm-atm9-12',
            'labels' => $labels,
            'created' => '2026-11-22T18:00:00+00:00',
        ];
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

    public function test_creating_a_server_passes_its_keys_and_labels(): void
    {
        Http::fake([
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
            sshKeyIds: [42],
            userData: "#cloud-config\nruncmd: []\n",
            labels: ['managed-by' => 'someone-else', 'pack' => 'atm9'],
        ));

        $this->assertSame(4711, $server->id);
        $this->assertSame('initializing', $server->status);
        $this->assertSame('203.0.113.10', $server->ipv4);
        $this->assertTrue($server->isManaged());
        $this->assertSame(7, $server->minecraftServerId());

        // One request: the key is the run's own, made beforehand (#5324), never looked up here.
        Http::assertSentCount(1);

        Http::assertSent(function (Request $r) {
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer test-token'));

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

    public function test_a_server_without_an_ssh_key_is_refused_before_any_request(): void
    {
        // Hetzner would set a root password and email it.
        Http::fake();

        try {
            $this->client()->createServer(new ServerSpec('a', 'cx43', 'fsn1', 1));
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_registering_an_ssh_key_labels_it(): void
    {
        Http::fake([
            self::API.'/ssh_keys' => Http::response(['ssh_key' => $this->sshKeyJson(42)], 201),
        ]);

        $key = $this->client()->createSshKey('mvm-atm9-12', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGmq mvm-atm9-12\n", [
            'mvm-run-id' => '12',
            'managed-by' => 'someone-else',
        ]);

        $this->assertSame(42, $key->id);
        $this->assertTrue($key->isManaged());
        $this->assertSame(12, $key->runId());

        Http::assertSent(function (Request $r) {
            $this->assertSame('POST', $r->method());
            $this->assertSame('mvm-atm9-12', $r['name']);
            $this->assertSame('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGmq mvm-atm9-12', $r['public_key']);
            $this->assertSame(['mvm-run-id' => '12', 'managed-by' => 'minecraft-vps-manager'], $r['labels']);

            return true;
        });
    }

    public function test_deleting_a_managed_ssh_key(): void
    {
        Http::fake([
            self::API.'/ssh_keys/42' => Http::sequence()
                ->push(['ssh_key' => $this->sshKeyJson(42)])
                ->push(null, 204),
        ]);

        $this->client()->deleteSshKey(42);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/ssh_keys/42');
    }

    public function test_a_key_the_app_did_not_make_is_never_deleted(): void
    {
        Http::fake([
            self::API.'/ssh_keys/7' => Http::response(['ssh_key' => $this->sshKeyJson(7, labels: [])]),
        ]);

        try {
            $this->client()->deleteSshKey(7);
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('not_managed', $e->errorCode);
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_deleting_a_key_that_is_already_gone_is_fine(): void
    {
        Http::fake([
            self::API.'/ssh_keys/42' => Http::response(['error' => ['code' => 'not_found', 'message' => 'ssh_key not found']], 404),
        ]);

        $this->client()->deleteSshKey(42);

        Http::assertSentCount(1);
    }

    public function test_managed_ssh_keys_are_listed_by_label_and_filtered(): void
    {
        Http::fake([
            self::API.'/ssh_keys?*' => Http::response([
                // The second has no label: Hetzner wouldn't return it for the selector, but if it
                // did, it still mustn't count as the app's.
                'ssh_keys' => [$this->sshKeyJson(42), $this->sshKeyJson(43, labels: [])],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null]],
            ]),
        ]);

        $keys = $this->client()->managedSshKeys();

        $this->assertSame([42], array_map(fn ($key) => $key->id, $keys));
        Http::assertSent(fn (Request $r) => $r['label_selector'] === 'managed-by=minecraft-vps-manager');
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
            self::API.'/servers' => Http::response(['error' => [
                'code' => 'resource_limit_exceeded',
                'message' => 'project limit exceeded',
                'details' => ['limits' => [['name' => 'project_limit']]],
            ]], 403),
        ]);

        try {
            $this->client()->createServer(new ServerSpec('a', 'cx43', 'fsn1', 1, sshKeyIds: [99]));
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
                // The shape since 2 Nov 2026: no type-wide `deprecated`, deprecation per location (#5321).
                'server_types' => [
                    [
                        'id' => 1, 'name' => 'cx43', 'description' => 'CX43', 'cores' => 8, 'memory' => 16.0, 'disk' => 160,
                        'deprecation' => null, 'storage_type' => 'local', 'cpu_type' => 'shared', 'architecture' => 'x86', 'category' => 'Shared vCPU',
                        'locations' => [['id' => 1, 'name' => 'fsn1', 'deprecation' => null, 'available' => true, 'recommended' => true]],
                        'prices' => [
                            ['location' => 'fsn1', 'price_hourly' => ['net' => '0.0160', 'gross' => '0.0190'], 'price_monthly' => ['net' => '9.9900', 'gross' => '11.8881'], 'included_traffic' => 21990232555520, 'price_per_tb_traffic' => ['net' => '1.0000', 'gross' => '1.1900']],
                        ],
                    ],
                    [
                        // Retired in fsn1, still sold in hel1, and Hetzner still lists fsn1's price.
                        'id' => 2, 'name' => 'cpx21', 'description' => 'CPX21', 'cores' => 3, 'memory' => 4, 'disk' => 80,
                        'deprecation' => null, 'cpu_type' => 'shared', 'architecture' => 'x86', 'category' => 'Shared vCPU',
                        'locations' => [
                            ['id' => 1, 'name' => 'fsn1', 'deprecation' => ['unavailable_after' => '2026-01-01T00:00:00+00:00', 'announced' => '2025-10-01T00:00:00+00:00'], 'available' => false, 'recommended' => false],
                            ['id' => 3, 'name' => 'hel1', 'deprecation' => null, 'available' => true, 'recommended' => false],
                        ],
                        'prices' => [
                            ['location' => 'fsn1', 'price_hourly' => ['net' => '0.0100', 'gross' => '0.0119'], 'price_monthly' => ['net' => '7.0000', 'gross' => '8.3300']],
                            ['location' => 'hel1', 'price_hourly' => ['net' => '0.0100', 'gross' => '0.0119'], 'price_monthly' => ['net' => '7.0000', 'gross' => '8.3300']],
                        ],
                    ],
                    [
                        // No category in the answer: grouped by CPU type.
                        'id' => 3, 'name' => 'ccx13', 'description' => 'CCX13 Dedicated CPU', 'cores' => 2, 'memory' => 8, 'disk' => 80,
                        'cpu_type' => 'dedicated', 'architecture' => 'x86',
                        'locations' => [['id' => 1, 'name' => 'fsn1', 'deprecation' => null]],
                        'prices' => [['location' => 'fsn1', 'price_hourly' => ['net' => '0.0600', 'gross' => '0.0714'], 'price_monthly' => ['net' => '35.0000', 'gross' => '41.6500']]],
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
        [$cx43, $cpx21, $ccx13] = $client->serverTypes();

        $this->assertSame('cx43', $cx43->name);
        $this->assertSame(16.0, $cx43->memoryGb);
        $this->assertSame('Shared vCPU', $cx43->category);
        $this->assertSame(['fsn1' => ['monthly' => '11.8881', 'hourly' => '0.0190']], $cx43->grossPrices);
        $this->assertTrue($cx43->availableIn('fsn1'));
        $this->assertFalse($cx43->availableIn('hel1'));
        $this->assertSame([], $cx43->retiredIn);

        $this->assertFalse($cpx21->availableIn('fsn1'));
        $this->assertTrue($cpx21->retiredIn('fsn1'));
        $this->assertTrue($cpx21->availableIn('hel1'));
        $this->assertSame(['hel1'], array_keys($cpx21->grossPrices));

        $this->assertSame('Dedicated vCPU', $ccx13->category);
        $this->assertTrue($ccx13->availableIn('fsn1'));

        $locations = $client->locations();
        $this->assertSame('fsn1', $locations[0]->name);
        $this->assertSame('Falkenstein', $locations[0]->city);
        $this->assertSame('DE', $locations[0]->country);
    }

    public function test_an_answer_without_per_location_info_falls_back_to_the_type_wide_deprecation(): void
    {
        $price = ['location' => 'fsn1', 'price_hourly' => ['gross' => '0.0100'], 'price_monthly' => ['gross' => '6.0000']];

        Http::fake([
            self::API.'/server_types?*' => Http::response([
                'server_types' => [
                    ['id' => 1, 'name' => 'cx33', 'description' => 'CX33', 'cores' => 4, 'memory' => 8, 'disk' => 80, 'cpu_type' => 'shared', 'architecture' => 'x86', 'deprecation' => null, 'prices' => [$price]],
                    ['id' => 2, 'name' => 'cx11', 'description' => 'CX11', 'cores' => 1, 'memory' => 2, 'disk' => 20, 'cpu_type' => 'shared', 'architecture' => 'x86',
                        'deprecation' => ['unavailable_after' => '2024-09-01T00:00:00+00:00', 'announced' => '2024-06-01T00:00:00+00:00'], 'prices' => [$price]],
                ],
                'meta' => ['pagination' => ['page' => 1, 'next_page' => null]],
            ]),
        ]);

        [$cx33, $cx11] = $this->client()->serverTypes();

        $this->assertTrue($cx33->availableIn('fsn1'));
        $this->assertSame('Shared vCPU', $cx33->category);
        $this->assertFalse($cx11->availableIn('fsn1'));
        $this->assertTrue($cx11->retiredIn('fsn1'));
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
