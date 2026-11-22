<?php

namespace App\Dns;

/**
 * Where records go: the zone's domain and id, and the token that may edit its DNS.
 */
final readonly class DnsZone
{
    public const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public const ZONE_ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        public string $domain,
        public string $zoneId,
        #[\SensitiveParameter] public string $token,
    ) {}

    public function withZoneId(string $zoneId): self
    {
        return new self($this->domain, $zoneId, $this->token);
    }

    /**
     * @return array<string, string> never the token
     */
    public function __debugInfo(): array
    {
        return ['domain' => $this->domain, 'zoneId' => $this->zoneId];
    }
}
