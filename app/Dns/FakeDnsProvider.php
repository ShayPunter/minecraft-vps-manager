<?php

namespace App\Dns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Cloudflare as tests and E2E see it (#5338): records live in the cache, so they survive
 * artisan serve's separate processes. Never calls anything.
 *
 *   token FAKE_BAD_TOKEN       → refused (403)
 *   zone  FAKE_MISSING_ZONE_ID → not found (404)
 *   zone  FAKE_OTHER_ZONE_ID   → belongs to FAKE_OTHER_DOMAIN, so a name in another domain gets
 *                                that domain appended, as Cloudflare does
 */
class FakeDnsProvider implements DnsProvider
{
    public const FAKE_BAD_TOKEN = 'bad-token';

    public const FAKE_MISSING_ZONE_ID = 'ffffffffffffffffffffffffffffffff';

    public const FAKE_OTHER_ZONE_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    public const FAKE_OTHER_DOMAIN = 'other.example';

    private const KEY = 'mvm:fake-dns:records';

    private const FAIL_KEY = 'mvm:fake-dns:fail';

    public function __construct(private readonly Repository $cache) {}

    public function recordsNamed(DnsZone $zone, string $name): array
    {
        $this->guard($zone);

        return array_values(array_filter($this->all($zone), fn (DnsRecord $r): bool => strtolower($r->name) === strtolower($name)));
    }

    public function managedRecords(DnsZone $zone): array
    {
        $this->guard($zone);

        return array_values(array_filter($this->all($zone), fn (DnsRecord $r): bool => $r->isManaged()));
    }

    public function createRecord(DnsZone $zone, string $type, string $name, string $content, string $comment): DnsRecord
    {
        $this->guard($zone);

        if ($zone->zoneId === self::FAKE_OTHER_ZONE_ID && ! str_ends_with($name, '.'.self::FAKE_OTHER_DOMAIN)) {
            $name .= '.'.self::FAKE_OTHER_DOMAIN;
        }

        $record = new DnsRecord(Str::lower(Str::random(32)), $type, $name, $content, $comment);
        $this->put($zone->zoneId, $record);

        return $record;
    }

    public function deleteRecord(DnsZone $zone, string $recordId): void
    {
        $this->guard($zone);
        $state = $this->state();

        if (! isset($state[$zone->zoneId][$recordId])) {
            throw new DnsException('Cloudflare: not found: Record does not exist. (code 81044)', 404);
        }

        unset($state[$zone->zoneId][$recordId]);
        $this->cache->forever(self::KEY, $state);
    }

    // ---- for tests ----

    /**
     * A record made by hand (no managed comment), or any record a test needs to exist.
     */
    public function seed(string $zoneId, string $type, string $name, string $content, ?string $comment = null): DnsRecord
    {
        $record = new DnsRecord(Str::lower(Str::random(32)), $type, $name, $content, $comment);
        $this->put($zoneId, $record);

        return $record;
    }

    /**
     * @return list<DnsRecord>
     */
    public function records(string $zoneId): array
    {
        return array_values(array_map(fn (array $row): DnsRecord => new DnsRecord(...$row), $this->state()[$zoneId] ?? []));
    }

    /**
     * Every call fails with this message until failWith(null).
     */
    public function failWith(?string $message): void
    {
        $message === null ? $this->cache->forget(self::FAIL_KEY) : $this->cache->forever(self::FAIL_KEY, $message);
    }

    private function guard(DnsZone $zone): void
    {
        $fail = $this->cache->get(self::FAIL_KEY);

        if (is_string($fail)) {
            throw new DnsException($fail, 500);
        }

        if ($zone->token === self::FAKE_BAD_TOKEN) {
            throw new DnsException('Cloudflare refused the token: Authentication error (code 10000)', 403);
        }

        if ($zone->zoneId === self::FAKE_MISSING_ZONE_ID) {
            throw new DnsException('Cloudflare: not found: Could not route to /zones/'.self::FAKE_MISSING_ZONE_ID.'/dns_records (code 7003)', 404);
        }
    }

    /**
     * @return list<DnsRecord>
     */
    private function all(DnsZone $zone): array
    {
        return $this->records($zone->zoneId);
    }

    private function put(string $zoneId, DnsRecord $record): void
    {
        $state = $this->state();
        $state[$zoneId][$record->id] = [
            'id' => $record->id, 'type' => $record->type, 'name' => $record->name,
            'content' => $record->content, 'comment' => $record->comment,
        ];
        $this->cache->forever(self::KEY, $state);
    }

    /**
     * @return array<string, array<string, array{id: string, type: string, name: string, content: string, comment: string|null}>>
     */
    private function state(): array
    {
        $state = $this->cache->get(self::KEY, []);

        return is_array($state) ? $state : [];
    }
}
