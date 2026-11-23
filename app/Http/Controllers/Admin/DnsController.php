<?php

namespace App\Http\Controllers\Admin;

use App\Dns\Dns;
use App\Dns\DnsException;
use App\Dns\DnsProvider;
use App\Dns\DnsRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DnsRequest;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Support\Heartbeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin → DNS (#5338): the Cloudflare zone server names live in. Saving tests the token
 * first (a TXT record is made and deleted). The token is stored encrypted and never sent
 * back to the browser.
 */
class DnsController extends Controller
{
    public function edit(Dns $dns, DnsProvider $provider): Response
    {
        $saved = $dns->saved();
        $zone = $dns->zone();
        $records = null;
        $recordsError = null;

        // What the app has in Cloudflare right now: one A record per running server with a name.
        if ($zone !== null) {
            try {
                $records = array_values(array_map(
                    fn (DnsRecord $record): array => ['name' => $record->name, 'content' => $record->content, 'waker' => $record->wakerServerId() !== null],
                    array_filter($provider->managedRecords($zone), fn (DnsRecord $record): bool => $record->type === 'A'),
                ));
            } catch (DnsException $e) {
                $recordsError = $e->getMessage();
            }
        }

        return Inertia::render('admin/dns/Edit', [
            'dns' => [
                'domain' => $saved->domain ?? '',
                'zone_id' => $saved->zone_id ?? '',
                'has_token' => $saved !== null,
                'updated_at' => $saved?->updated_at?->toIso8601String(),
                'waker_ipv4' => $saved->waker_ipv4 ?? '',
                'waker_seen_at' => Heartbeat::last(Heartbeat::WAKER)?->toIso8601String(),
            ],
            'problem' => $dns->problem(),
            'records' => $records,
            'recordsError' => $recordsError,
            'servers' => Server::query()->whereNotNull('subdomain')->orderBy('name')->get(['name', 'slug', 'subdomain'])
                ->map(fn (Server $server): array => [
                    'name' => $server->name,
                    'slug' => $server->slug,
                    'address' => $dns->nameFor($server),
                ])->all(),
        ]);
    }

    /**
     * Test, and save only if the test passes.
     */
    public function update(DnsRequest $request, Dns $dns): RedirectResponse
    {
        $zone = $request->zone();
        $error = $zone !== null ? $dns->test($zone) : 'Paste the API token.';

        if ($error !== null || $zone === null) {
            $this->log($request, 'DNS settings not saved: test failed');

            throw ValidationException::withMessages(['connection' => "Not saved. {$error}"]);
        }

        $row = $dns->saved() ?? new DnsSetting;
        $row->domain = $zone->domain;
        $row->zone_id = $zone->zoneId;
        $row->api_token = $zone->token;
        $waker = $request->validated('waker_ipv4');
        $row->waker_ipv4 = is_string($waker) ? $waker : null;
        $row->updated_by = $request->user()?->id;
        $row->save();

        // The waker's address may have changed (#5620): move the parked names. Never in the
        // way of the save; the sweep does it again within 10 minutes.
        try {
            $dns->syncParked();
        } catch (Throwable $e) {
            Log::warning("Waker DNS records not synced: {$e->getMessage()}");
        }

        $this->log($request, 'DNS settings saved');

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved. The token can edit {$zone->domain}'s DNS."]);

        return to_route('admin.dns.edit');
    }

    /**
     * Never the token.
     */
    private function log(Request $request, string $message): void
    {
        Log::info($message, [
            'by' => $request->user()?->id,
            'domain' => $request->input('domain'),
            'zone_id' => $request->input('zone_id'),
            'waker_ipv4' => $request->input('waker_ipv4'),
        ]);
    }
}
