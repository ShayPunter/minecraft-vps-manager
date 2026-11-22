<?php

namespace App\Cloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Hetzner Cloud API v1 (https://docs.hetzner.cloud/reference/cloud). Never used in tests
 * or E2E: AppServiceProvider binds FakeCloudProvider there.
 */
class HetznerCloud implements CloudProvider
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl,
    ) {}

    public function createServer(ServerSpec $spec): CloudServer
    {
        if ($spec->sshKeyIds === []) {
            // Without a key Hetzner sets a root password and emails it: never.
            throw new CloudException('not_configured', 'A VPS needs at least one SSH key.');
        }

        $body = [
            'name' => $spec->name,
            'server_type' => $spec->serverType,
            'location' => $spec->location,
            'image' => $spec->image,
            'ssh_keys' => $spec->sshKeyIds,
            'labels' => $spec->allLabels(),
            'start_after_create' => true,
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
        ];

        if ($spec->userData !== null) {
            $body['user_data'] = $spec->userData;
        }

        $server = $this->send('post', '/servers', $body)->json('server');

        // With an SSH key Hetzner sets no root password; `root_password` in the reply is
        // ignored on purpose either way.
        return $this->toServer($server);
    }

    public function findServer(int $id): ?CloudServer
    {
        $response = $this->send('get', "/servers/{$id}", allowNotFound: true);

        return $response === null ? null : $this->toServer($response->json('server'));
    }

    public function deleteServer(int $id): void
    {
        $server = $this->findServer($id);

        if ($server === null) {
            return;
        }

        if (! $server->isManaged()) {
            throw new CloudException('not_managed', "Server {$id} wasn't created by this app; refusing to delete it.");
        }

        $this->send('delete', "/servers/{$id}", allowNotFound: true);
    }

    public function managedServers(): array
    {
        $selector = self::MANAGED_LABEL.'='.self::MANAGED_VALUE;

        $servers = array_map(
            fn (mixed $server) => $this->toServer($server),
            $this->paginate('/servers', 'servers', ['label_selector' => $selector]),
        );

        // Belt and braces: only ever hand back servers that really carry the label.
        return array_values(array_filter($servers, fn (CloudServer $server) => $server->isManaged()));
    }

    public function serverTypes(): array
    {
        return array_map(function (mixed $type): ServerType {
            $type = $this->object($type);
            $cpuType = (string) ($type['cpu_type'] ?? 'shared');
            $architecture = (string) ($type['architecture'] ?? 'x86');

            // Since Sep 2025 deprecation is per location (`locations[].deprecation`); the old
            // type-wide `deprecated` was removed on 2 Nov 2026 (#5321). A location missing from
            // `locations` doesn't offer the type. Older answers without `locations` fall back to
            // the type-wide `deprecation`.
            $hasLocations = array_key_exists('locations', $type);
            $offered = [];
            $retired = [];

            foreach ($this->list($type['locations'] ?? []) as $location) {
                $location = $this->object($location);
                $name = (string) ($location['name'] ?? '');

                if (($location['deprecation'] ?? null) !== null) {
                    $retired[] = $name;
                } else {
                    $offered[] = $name;
                }
            }

            $retiredEverywhere = ! $hasLocations && (($type['deprecation'] ?? null) !== null || (bool) ($type['deprecated'] ?? false));
            $prices = [];

            foreach ($this->list($type['prices'] ?? []) as $price) {
                $price = $this->object($price);
                $location = (string) $price['location'];

                if ($retiredEverywhere) {
                    $retired[] = $location;

                    continue;
                }

                if ($hasLocations && ! in_array($location, $offered, true)) {
                    continue;
                }

                $prices[$location] = [
                    'monthly' => (string) ($this->object($price['price_monthly'] ?? [])['gross'] ?? ''),
                    'hourly' => (string) ($this->object($price['price_hourly'] ?? [])['gross'] ?? ''),
                ];
            }

            $category = trim((string) ($type['category'] ?? ''));

            return new ServerType(
                name: (string) $type['name'],
                description: (string) ($type['description'] ?? $type['name']),
                cores: (int) ($type['cores'] ?? 0),
                memoryGb: (float) ($type['memory'] ?? 0),
                diskGb: (int) ($type['disk'] ?? 0),
                cpuType: $cpuType,
                architecture: $architecture,
                category: $category !== '' ? $category : ($cpuType === 'dedicated' ? 'Dedicated vCPU' : 'Shared vCPU'),
                grossPrices: $prices,
                retiredIn: array_values(array_unique($retired)),
            );
        }, $this->paginate('/server_types', 'server_types'));
    }

    public function locations(): array
    {
        return array_map(function (mixed $location): Location {
            $location = $this->object($location);

            return new Location(
                name: (string) $location['name'],
                description: (string) ($location['description'] ?? $location['name']),
                city: (string) ($location['city'] ?? ''),
                country: (string) ($location['country'] ?? ''),
            );
        }, $this->paginate('/locations', 'locations'));
    }

    public function createSshKey(string $name, string $publicKey, array $labels = []): CloudSshKey
    {
        $created = $this->send('post', '/ssh_keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
            'labels' => [...$labels, self::MANAGED_LABEL => self::MANAGED_VALUE],
        ])->json('ssh_key');

        return $this->toSshKey($created);
    }

    public function deleteSshKey(int $id): void
    {
        $response = $this->send('get', "/ssh_keys/{$id}", allowNotFound: true);

        if ($response === null) {
            return;
        }

        if (! $this->toSshKey($response->json('ssh_key'))->isManaged()) {
            throw new CloudException('not_managed', "SSH key {$id} wasn't created by this app; refusing to delete it.");
        }

        $this->send('delete', "/ssh_keys/{$id}", allowNotFound: true);
    }

    public function managedSshKeys(): array
    {
        $selector = self::MANAGED_LABEL.'='.self::MANAGED_VALUE;

        $keys = array_map(
            fn (mixed $key) => $this->toSshKey($key),
            $this->paginate('/ssh_keys', 'ssh_keys', ['label_selector' => $selector]),
        );

        return array_values(array_filter($keys, fn (CloudSshKey $key) => $key->isManaged()));
    }

    private function toSshKey(mixed $key): CloudSshKey
    {
        $key = $this->object($key);

        return new CloudSshKey(
            id: (int) $key['id'],
            name: (string) ($key['name'] ?? ''),
            labels: array_map(fn (mixed $value) => (string) $value, $this->object($key['labels'] ?? [])),
        );
    }

    /**
     * Every item of a paginated list endpoint.
     *
     * @param  array<string, string>  $query
     * @return list<mixed>
     */
    private function paginate(string $path, string $key, array $query = []): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->send('get', $path, [...$query, 'page' => $page, 'per_page' => 50]);
            array_push($items, ...$this->list($response->json($key)));
            $next = $response->json('meta.pagination.next_page');
            $page = is_int($next) && $next > $page ? $next : null;
        } while ($page !== null);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ($allowNotFound is true ? Response|null : Response)
     */
    private function send(string $method, string $path, array $data = [], bool $allowNotFound = false): ?Response
    {
        try {
            $response = $this->client()->send(strtoupper($method), ltrim($path, '/'), match ($method) {
                'get' => ['query' => $data],
                default => $data === [] ? [] : ['json' => $data],
            });
        } catch (ConnectionException $e) {
            throw new CloudException('connection_failed', 'Could not reach Hetzner: '.$e->getMessage());
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            $code = $response->json('error.code');
            $message = $response->json('error.message');

            throw new CloudException(
                is_string($code) ? $code : 'unexpected_response',
                'Hetzner: '.(is_string($message) ? $message : "HTTP {$response->status()}"),
                $response->status(),
            );
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        if ($this->token === null || $this->token === '') {
            throw new CloudException('not_configured', 'HETZNER_TOKEN is not set.');
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function toServer(mixed $server): CloudServer
    {
        $server = $this->object($server);
        $ipv4 = $this->object($this->object($server['public_net'] ?? [])['ipv4'] ?? [])['ip'] ?? null;
        $labels = array_map(fn (mixed $value) => (string) $value, $this->object($server['labels'] ?? []));

        return new CloudServer(
            id: (int) $server['id'],
            name: (string) $server['name'],
            status: (string) ($server['status'] ?? 'unknown'),
            ipv4: is_string($ipv4) ? $ipv4 : null,
            labels: $labels,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new CloudException('unexpected_response', 'Hetzner answered with an unexpected shape.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
