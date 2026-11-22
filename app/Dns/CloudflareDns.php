<?php

namespace App\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare's DNS records API (#5338). Only /zones/{zone_id}/dns_records is used, so a token
 * made from the "Edit zone DNS" template for one zone is all it needs.
 */
class CloudflareDns implements DnsProvider
{
    private const PER_PAGE = 100;

    public function __construct(private readonly string $baseUrl = 'https://api.cloudflare.com/client/v4') {}

    public function recordsNamed(DnsZone $zone, string $name): array
    {
        $records = $this->list($zone, ['name' => $name]);

        // The filter is exact, but never act on a record the API sent for another name.
        return array_values(array_filter($records, fn (DnsRecord $record): bool => strtolower($record->name) === strtolower($name)));
    }

    public function managedRecords(DnsZone $zone): array
    {
        try {
            $records = $this->list($zone, ['comment.startswith' => DnsRecord::MANAGED]);
        } catch (DnsException $e) {
            if ($e->status !== 400) {
                throw $e;
            }

            // The comment filter isn't promised by the docs: if it's refused, list everything.
            $records = $this->list($zone, []);
        }

        return array_values(array_filter($records, fn (DnsRecord $record): bool => $record->isManaged()));
    }

    public function createRecord(DnsZone $zone, string $type, string $name, string $content, string $comment): DnsRecord
    {
        $body = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => 60, 'comment' => $comment];

        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $body['proxied'] = false;
        }

        $result = $this->result($this->send($zone, fn (PendingRequest $http) => $http->post($this->recordsUrl($zone), $body)));

        return $this->record(is_array($result) ? $result : []);
    }

    public function deleteRecord(DnsZone $zone, string $recordId): void
    {
        $this->result($this->send($zone, fn (PendingRequest $http) => $http->delete($this->recordsUrl($zone).'/'.rawurlencode($recordId))));
    }

    /**
     * @param  array<string, string>  $query
     * @return list<DnsRecord>
     */
    private function list(DnsZone $zone, array $query): array
    {
        $records = [];

        for ($page = 1; $page <= 20; $page++) {
            $response = $this->send($zone, fn (PendingRequest $http) => $http->get($this->recordsUrl($zone), [
                ...$query, 'per_page' => self::PER_PAGE, 'page' => $page,
            ]));
            $result = $this->result($response);

            foreach (is_array($result) ? $result : [] as $row) {
                if (is_array($row)) {
                    $records[] = $this->record($row);
                }
            }

            $totalPages = (int) $response->json('result_info.total_pages', 1);

            if ($page >= $totalPages) {
                break;
            }
        }

        return $records;
    }

    private function recordsUrl(DnsZone $zone): string
    {
        return rtrim($this->baseUrl, '/').'/zones/'.rawurlencode($zone->zoneId).'/dns_records';
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(DnsZone $zone, callable $call): Response
    {
        // The token comes with each call: the provider is a singleton, the token lives in the database.
        try {
            return $call(Http::withToken($zone->token)->acceptJson()->timeout(10)->connectTimeout(5));
        } catch (ConnectionException $e) {
            throw new DnsException("Couldn't reach Cloudflare: {$e->getMessage()}");
        }
    }

    /**
     * @return mixed the "result" of a successful answer
     *
     * @throws DnsException
     */
    private function result(Response $response): mixed
    {
        if ($response->successful() && $response->json('success') === true) {
            return $response->json('result');
        }

        $errors = $response->json('errors');
        $first = is_array($errors) && isset($errors[0]) && is_array($errors[0]) ? $errors[0] : [];
        $message = is_string($first['message'] ?? null) ? $first['message'] : 'HTTP '.$response->status();
        $code = isset($first['code']) && is_scalar($first['code']) ? " (code {$first['code']})" : '';

        $prefix = match ($response->status()) {
            401, 403 => 'Cloudflare refused the token: ',
            404 => 'Cloudflare: not found: ',
            default => 'Cloudflare said: ',
        };

        throw new DnsException(mb_substr($prefix.$message.$code, 0, 300), $response->status());
    }

    /**
     * @param  array<mixed>  $row
     */
    private function record(array $row): DnsRecord
    {
        return new DnsRecord(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            type: is_string($row['type'] ?? null) ? $row['type'] : '',
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            content: is_string($row['content'] ?? null) ? $row['content'] : '',
            comment: is_string($row['comment'] ?? null) ? $row['comment'] : null,
        );
    }
}
