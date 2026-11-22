<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\Location;
use App\Cloud\ServerType;
use Illuminate\Support\Facades\Cache;

/**
 * The provider's server types and locations for the admin form, cached for an hour,
 * plus the default for new servers (#5276: the cheapest type with 8 GB in Germany).
 */
class ServerCatalog
{
    private const CACHE_KEY = 'mvm:catalog';

    /** @var array{types: list<ServerType>, locations: list<Location>}|null */
    private ?array $loaded = null;

    public function __construct(private readonly CloudProvider $cloud) {}

    /**
     * @return list<ServerType>
     *
     * @throws CloudException
     */
    public function types(): array
    {
        return $this->load()['types'];
    }

    /**
     * @return list<Location>
     *
     * @throws CloudException
     */
    public function locations(): array
    {
        return $this->load()['locations'];
    }

    /**
     * @throws CloudException
     */
    public function findType(string $name): ?ServerType
    {
        foreach ($this->types() as $type) {
            if ($type->name === $name) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{server_type: string|null, location: string|null}
     *
     * @throws CloudException
     */
    public function defaults(): array
    {
        $country = (string) config('minecraft.default_country');
        $location = null;

        foreach ($this->locations() as $candidate) {
            if ($candidate->country === $country) {
                $location = $candidate->name;
                break;
            }
        }

        $location ??= $this->locations()[0]->name ?? null;

        $best = null;
        $bestPrice = INF;

        foreach ($this->types() as $type) {
            if ($type->deprecated || $location === null || ! $type->availableIn($location)
                || $type->memoryGb < (float) config('minecraft.default_memory_gb')) {
                continue;
            }

            $price = (float) $type->monthlyGrossPrices[$location];

            if ($price < $bestPrice) {
                $best = $type->name;
                $bestPrice = $price;
            }
        }

        return ['server_type' => $best, 'location' => $location];
    }

    /**
     * For the admin form: plain arrays, prices per location.
     *
     * @return array{types: list<array<string, mixed>>, locations: list<array<string, string>>, defaults: array{server_type: string|null, location: string|null}, error: string|null}
     */
    public function formOptions(): array
    {
        try {
            return [
                'types' => array_map(fn (ServerType $type): array => [
                    'name' => $type->name,
                    'label' => sprintf('%s · %d vCPU · %s GB RAM · %d GB disk%s', $type->description, $type->cores, rtrim(rtrim(number_format($type->memoryGb, 1), '0'), '.'), $type->diskGb, $type->cpuType === 'dedicated' ? ' · dedicated' : ''),
                    'prices' => array_map(fn (string $price): string => '€'.number_format((float) $price, 2).'/month', $type->monthlyGrossPrices),
                ], array_values(array_filter($this->types(), fn (ServerType $type): bool => ! $type->deprecated))),
                'locations' => array_map(fn (Location $location): array => [
                    'name' => $location->name,
                    'label' => "{$location->city}, {$location->country} ({$location->name})",
                ], $this->locations()),
                'defaults' => $this->defaults(),
                'error' => null,
            ];
        } catch (CloudException $e) {
            return [
                'types' => [],
                'locations' => [],
                'defaults' => ['server_type' => null, 'location' => null],
                'error' => "Couldn't load server types from the cloud provider: {$e->getMessage()}",
            ];
        }
    }

    /**
     * @return array{types: list<ServerType>, locations: list<Location>}
     *
     * @throws CloudException
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        // Plain arrays in the cache: config/cache.php allows no serialized objects.
        /** @var array{types: list<array<string, mixed>>, locations: list<array<string, mixed>>} $cached */
        $cached = Cache::remember(self::CACHE_KEY, now()->addHour(), fn (): array => [
            'types' => array_map(fn (ServerType $type): array => get_object_vars($type), $this->cloud->serverTypes()),
            'locations' => array_map(fn (Location $location): array => get_object_vars($location), $this->cloud->locations()),
        ]);

        return $this->loaded = [
            'types' => array_map(fn (array $type): ServerType => new ServerType(...$type), $cached['types']),
            'locations' => array_map(fn (array $location): Location => new Location(...$location), $cached['locations']),
        ];
    }
}
