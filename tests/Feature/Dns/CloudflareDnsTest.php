<?php

namespace Tests\Feature\Dns;

use App\Dns\CloudflareDns;
use App\Dns\DnsException;
use App\Dns\DnsRecord;
use App\Dns\DnsZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Cloudflare client against Http::fake(), with response shapes from
 * https://developers.cloudflare.com/api/resources/dns/subresources/records/. Nothing here
 * reaches the real API.
 */
class CloudflareDnsTest extends TestCase
{
    private const API = 'https://api.cloudflare.com/client/v4';

    private const ZONE_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    private function client(): CloudflareDns
    {
        return new CloudflareDns(self::API);
    }

    private function zone(): DnsZone
    {
        return new DnsZone('example.com', self::ZONE_ID, 'cf-secret-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function recordJson(string $id, string $name, ?string $comment, string $type = 'A'): array
    {
        return [
            'id' => $id, 'zone_id' => self::ZONE_ID, 'zone_name' => 'example.com', 'name' => $name,
            'type' => $type, 'content' => '203.0.113.7', 'proxiable' => true, 'proxied' => false,
            'ttl' => 60, 'comment' => $comment, 'tags' => [], 'created_on' => '2026-11-22T20:00:00Z',
        ];
    }

    /**
     * @param  array<mixed>  $result
     * @return array<string, mixed>
     */
    private function ok(array $result, int $page = 1, int $totalPages = 1): array
    {
        return [
            'success' => true, 'errors' => [], 'messages' => [], 'result' => $result,
            'result_info' => ['page' => $page, 'per_page' => 100, 'count' => count($result), 'total_count' => count($result), 'total_pages' => $totalPages],
        ];
    }

    public function test_it_creates_a_dns_only_a_record_with_the_token_and_a_short_ttl(): void
    {
        Http::fake([self::API.'/zones/'.self::ZONE_ID.'/dns_records' => Http::response($this->ok($this->recordJson('rec1', 'atm9.example.com', DnsRecord::commentForRun(12))))]);

        $record = $this->client()->createRecord($this->zone(), 'A', 'atm9.example.com', '203.0.113.7', DnsRecord::commentForRun(12));

        $this->assertSame('rec1', $record->id);
        $this->assertSame(12, $record->runId());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer cf-secret-token')
            && $request->data() === [
                'type' => 'A', 'name' => 'atm9.example.com', 'content' => '203.0.113.7', 'ttl' => 60,
                'comment' => 'managed-by=minecraft-vps-manager mvm-run-id=12', 'proxied' => false,
            ]);
    }

    public function test_a_txt_record_is_sent_without_proxied(): void
    {
        Http::fake(['*' => Http::response($this->ok($this->recordJson('rec2', '_t.example.com', 'x', 'TXT')))]);

        $this->client()->createRecord($this->zone(), 'TXT', '_t.example.com', '"x"', DnsRecord::MANAGED.' test');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('proxied', $request->data()));
    }

    public function test_records_by_name_use_the_exact_filter_and_drop_other_names(): void
    {
        Http::fake(['*' => Http::response($this->ok([
            $this->recordJson('a', 'atm9.example.com', null, 'CNAME'),
            $this->recordJson('b', 'other.example.com', null),
        ]))]);

        $records = $this->client()->recordsNamed($this->zone(), 'atm9.example.com');

        $this->assertSame(['a'], array_map(fn (DnsRecord $r) => $r->id, $records));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), self::API.'/zones/'.self::ZONE_ID.'/dns_records?')
            && $request['name'] === 'atm9.example.com' && (int) $request['per_page'] === 100);
    }

    public function test_managed_records_page_through_and_keep_only_the_apps_comments(): void
    {
        Http::fakeSequence()
            ->push($this->ok([$this->recordJson('m1', 'a.example.com', DnsRecord::commentForRun(1))], 1, 2))
            ->push($this->ok([
                $this->recordJson('m2', 'b.example.com', DnsRecord::MANAGED.' test', 'TXT'),
                // A filter the API ignored must not let a hand-made record through.
                $this->recordJson('mine', 'nas.example.com', 'my NAS'),
            ], 2, 2));

        $records = $this->client()->managedRecords($this->zone());

        $this->assertSame(['m1', 'm2'], array_map(fn (DnsRecord $r) => $r->id, $records));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['comment.startswith'] === DnsRecord::MANAGED && (int) $request['page'] === 2);
    }

    public function test_a_refused_comment_filter_falls_back_to_listing_everything(): void
    {
        Http::fakeSequence()
            ->push(['success' => false, 'errors' => [['code' => 9000, 'message' => 'Invalid filter']], 'messages' => [], 'result' => null], 400)
            ->push($this->ok([
                $this->recordJson('m1', 'a.example.com', DnsRecord::commentForRun(1)),
                $this->recordJson('mine', 'nas.example.com', null),
            ]));

        $records = $this->client()->managedRecords($this->zone());

        $this->assertSame(['m1'], array_map(fn (DnsRecord $r) => $r->id, $records));
        Http::assertSentCount(2);
    }

    public function test_delete_sends_the_record_id(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'rec1']])]);

        $this->client()->deleteRecord($this->zone(), 'rec1');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::API.'/zones/'.self::ZONE_ID.'/dns_records/rec1');
    }

    public function test_a_refused_token_says_so_without_the_token(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'messages' => [], 'result' => null], 403)]);

        try {
            $this->client()->recordsNamed($this->zone(), 'atm9.example.com');
            $this->fail('No exception.');
        } catch (DnsException $e) {
            $this->assertSame('Cloudflare refused the token: Authentication error (code 10000)', $e->getMessage());
            $this->assertSame(403, $e->status);
            $this->assertStringNotContainsString('cf-secret-token', $e->getMessage());
        }
    }

    public function test_a_missing_record_is_a_not_found(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']], 'messages' => [], 'result' => null], 404)]);

        try {
            $this->client()->deleteRecord($this->zone(), 'gone');
            $this->fail('No exception.');
        } catch (DnsException $e) {
            $this->assertTrue($e->isNotFound());
        }
    }

    public function test_an_unreachable_api_is_a_dns_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(DnsException::class);
        $this->expectExceptionMessage("Couldn't reach Cloudflare: cURL error 28");

        $this->client()->managedRecords($this->zone());
    }

    public function test_the_zone_never_prints_its_token(): void
    {
        $this->assertStringNotContainsString('cf-secret-token', print_r($this->zone(), true));
    }
}
