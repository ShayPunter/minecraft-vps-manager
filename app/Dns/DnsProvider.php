<?php

namespace App\Dns;

/**
 * DNS records in one zone (#5338). CloudflareDns talks to the real API; FakeDnsProvider is
 * used in tests and E2E.
 */
interface DnsProvider
{
    /**
     * Every record (any type) with exactly this name.
     *
     * @return list<DnsRecord>
     *
     * @throws DnsException
     */
    public function recordsNamed(DnsZone $zone, string $name): array;

    /**
     * Every record whose comment starts with DnsRecord::MANAGED.
     *
     * @return list<DnsRecord>
     *
     * @throws DnsException
     */
    public function managedRecords(DnsZone $zone): array;

    /**
     * A DNS-only record (never proxied: Minecraft isn't HTTP) with a 60 s TTL.
     *
     * @throws DnsException
     */
    public function createRecord(DnsZone $zone, string $type, string $name, string $content, string $comment): DnsRecord;

    /**
     * @throws DnsException (isNotFound() when it was already gone)
     */
    public function deleteRecord(DnsZone $zone, string $recordId): void;
}
