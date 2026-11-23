<?php

namespace Tests\Feature\Waker;

use App\Enums\RunStatus;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\GuestStarts;
use App\Waker\Favicons;
use App\Waker\WakerReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * What the waker tells players (#5620): the server-list entry and what a join does.
 */
class WakerRepliesTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $row->api_token = 'cf-token-123';
        $row->waker_ipv4 = '198.51.100.10';
        $row->save();

        config(['app.url' => 'https://mc.example.com']);
    }

    private function replies(): WakerReplies
    {
        return $this->app->make(WakerReplies::class);
    }

    private function server(bool $wake = true): Server
    {
        return Server::factory()->create(['name' => 'ATM9', 'slug' => 'atm9', 'subdomain' => 'atm9', 'wake_on_join' => $wake]);
    }

    /**
     * An ended run two days ago (so it isn't one of today's guest starts) that took $seconds to come online.
     */
    private function pastRun(Server $server, int $seconds): void
    {
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->status = RunStatus::Stopped;
        $run->created_at = now()->subDays(2);
        $run->online_at = now()->subDays(2)->addSeconds($seconds);
        $run->ended_at = now()->subDays(2)->addHour();
        $run->started_by = User::factory()->create()->id;
        $run->world_archive_id = 1;
        $run->save();
    }

    private function activeRun(Server $server, RunStatus $status, ?string $dnsRecordId = null, int $startedSecondsAgo = 60): ServerRun
    {
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = $server->id;
        $run->status = $status;
        $run->ipv4 = '203.0.113.5';
        $run->dns_record_id = $dnsRecordId;
        $run->created_at = now()->subSeconds($startedSecondsAgo);
        $run->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private static function line(array $status): string
    {
        return $status['description']['extra'][0]['text'];
    }

    public function test_a_sleeping_server_shows_its_name_and_join_to_start_it(): void
    {
        $server = $this->server();

        $status = $this->replies()->status('atm9.example.com');

        $this->assertSame(['name' => 'Asleep', 'protocol' => -1], $status['version']);
        $this->assertSame('ATM9', $status['description']['text']);
        $this->assertSame("\nAsleep. Join to start it.", self::line($status));
        $this->assertSame(0, $status['players']['online']);
        $this->assertArrayNotHasKey('favicon', $status);

        // With history: how long a start usually takes.
        $this->pastRun($server, 170);
        $this->assertSame("\nAsleep. Join to start it (ready in about 3 min).", self::line($this->replies()->status('atm9.example.com')));

        // A ping never starts it.
        $this->assertFalse($server->activeRun()->exists());
    }

    public function test_the_entry_follows_the_run(): void
    {
        $server = $this->server();
        $this->pastRun($server, 300);

        $run = $this->activeRun($server, RunStatus::Booting, startedSecondsAgo: 120);
        $status = $this->replies()->status('atm9.example.com');
        $this->assertSame('Starting', $status['version']['name']);
        $this->assertSame("\nStarting: about 3 min left.", self::line($status));

        $run->created_at = now()->subMinutes(6);
        $run->save();
        $this->assertSame("\nStarting: any moment now.", self::line($this->replies()->status('atm9.example.com')));

        $run->status = RunStatus::Online;
        $run->save();
        $this->assertSame("\nUp at 203.0.113.5. Join that address.", self::line($this->replies()->status('atm9.example.com')));

        $run->dns_record_id = 'rec-1';
        $run->save();
        $this->assertSame('Online', $this->replies()->status('atm9.example.com')['version']['name']);
        $this->assertSame("\nIt's up! Refresh in a minute to see it.", self::line($this->replies()->status('atm9.example.com')));

        $run->status = RunStatus::Stopping;
        $run->save();
        $this->assertSame('Stopping', $this->replies()->status('atm9.example.com')['version']['name']);
    }

    public function test_unknown_addresses_get_a_plain_entry(): void
    {
        $this->server();

        foreach (['198.51.100.10', 'other.example.com', 'example.com', 'atm9.example.org', ''] as $host) {
            $status = $this->replies()->status($host);
            $this->assertSame('No server here', $status['version']['name'], $host);
            $this->assertSame('Nothing at this address', $status['description']['text']);
            $this->assertSame('There is no server at this address.', $this->replies()->join($host, 'Steve', self::IP));
        }

        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_a_join_starts_it(): void
    {
        $server = $this->server();
        $this->pastRun($server, 240);
        Log::spy();

        $message = $this->replies()->join('atm9.example.com', 'Steve', self::IP);

        $this->assertSame("Starting ATM9 for you! It's usually ready in about 4 min: join again then.", $message);
        $run = $server->activeRun()->sole();
        $this->assertNull($run->started_by);
        $this->assertTrue($run->started_by_join);
        $this->assertSame(self::IP, $run->started_from_ip);
        // Not a guest start: the website's guest budget is untouched (#5818).
        $this->assertSame(0, GuestStarts::usedToday($server));
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []): bool => $m === 'Server started by a join'
            && $c === ['server' => 'atm9', 'player' => 'Steve', 'ip' => self::IP]);

        // Joining again doesn't start a second one.
        $this->assertStringStartsWith('ATM9 is starting: about', $this->replies()->join('atm9.example.com', 'Alex', '198.51.100.8'));
        $this->assertSame(2, ServerRun::query()->count());
    }

    public function test_the_guest_rules_dont_apply_to_a_join_but_the_cap_does(): void
    {
        // Guests can't start anything from the website, and one of the two slots is taken:
        // a guest would get "the last free slot is kept for signed-in players" (#5818).
        config(['minecraft.guest_starts_per_day' => 0, 'minecraft.max_running' => 2, 'minecraft.guest_free_slots' => 1]);
        $other = Server::factory()->create(['name' => 'Other', 'slug' => 'other']);
        $this->activeRun($other, RunStatus::Online);
        $server = $this->server();

        $this->assertSame('Starting ATM9 for you! Join again in a few minutes.', $this->replies()->join('atm9.example.com', 'Steve', self::IP));
        $this->assertTrue($server->activeRun()->exists());

        // The cap still holds.
        $third = Server::factory()->create(['name' => 'Third', 'slug' => 'third', 'subdomain' => 'third', 'wake_on_join' => true]);
        $this->assertSame("Third can't start: 2 servers are already running, the most allowed at once. Try again when one has stopped.", $this->replies()->join('third.example.com', 'Steve', '198.51.100.9'));
        $this->assertFalse($third->activeRun()->exists());
    }

    public function test_joins_from_one_address_are_limited(): void
    {
        config(['minecraft.max_running' => 0]);
        $this->server();

        for ($i = 0; $i < WakerReplies::STARTS_PER_MINUTE; $i++) {
            $this->assertStringContainsString("can't start", $this->replies()->join('atm9.example.com', 'Steve', self::IP));
        }

        $this->assertSame('Too many tries. Wait a minute and join again.', $this->replies()->join('atm9.example.com', 'Steve', self::IP));
        // Another address isn't held up.
        $this->assertStringContainsString("can't start", $this->replies()->join('atm9.example.com', 'Steve', '198.51.100.8'));
    }

    public function test_a_server_that_doesnt_wake_on_join_points_at_the_website(): void
    {
        $this->server(wake: false);

        $this->assertSame("\nAsleep. Start it on the website.", self::line($this->replies()->status('atm9.example.com')));
        $this->assertSame('ATM9 is asleep. Start it on the website: https://mc.example.com', $this->replies()->join('atm9.example.com', 'Steve', self::IP));
        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_joins_while_it_runs_or_stops_say_what_to_do(): void
    {
        $server = $this->server();
        $run = $this->activeRun($server, RunStatus::Online);

        $this->assertSame('ATM9 is up at 203.0.113.5. Join that address.', $this->replies()->join('atm9.example.com', 'Steve', self::IP));

        $run->dns_record_id = 'rec-1';
        $run->save();
        $this->assertSame('ATM9 is up! Your computer still has its old address: wait a minute and join again.', $this->replies()->join('atm9.example.com', 'Steve', self::IP));

        $run->status = RunStatus::StopFailed;
        $run->save();
        $this->assertSame('ATM9 is saving its world and stopping. Join again in a few minutes to start it.', $this->replies()->join('atm9.example.com', 'Steve', self::IP));
        $this->assertSame(1, ServerRun::query()->count());
    }

    public function test_the_icon_comes_from_the_cache_the_sweep_fills(): void
    {
        if (! Favicons::supported()) {
            $this->markTestSkipped('GD is not loaded (run with -d extension=gd).');
        }

        $server = $this->server();
        $server->update(['icon_url' => 'https://images.example.com/atm9.png']);
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);
        Http::fake(['images.example.com/*' => Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);

        $this->assertArrayNotHasKey('favicon', $this->replies()->status('atm9.example.com'));

        $this->artisan('servers:sweep')->assertSuccessful();

        $favicon = $this->replies()->status('atm9.example.com')['favicon'];
        $this->assertStringStartsWith('data:image/png;base64,', $favicon);
        $png = (string) base64_decode(substr($favicon, strlen('data:image/png;base64,')), true);
        $this->assertSame([64, 64], array_slice((array) getimagesizefromstring($png), 0, 2));

        // Cached: the next sweep doesn't fetch again; a new URL is fetched; no URL, no icon.
        $this->artisan('servers:sweep')->assertSuccessful();
        Http::assertSentCount(1);
        $server->update(['icon_url' => null]);
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->assertArrayNotHasKey('favicon', $this->replies()->status('atm9.example.com'));
    }

    public function test_an_icon_that_cant_be_fetched_or_read_is_left_out_and_tried_later(): void
    {
        $server = $this->server();
        $server->update(['icon_url' => 'https://images.example.com/broken.png']);
        Http::fake(['images.example.com/*' => Http::response('not an image')]);

        app(Favicons::class)->warm($server);
        $this->assertNull(app(Favicons::class)->get($server));
        $this->assertTrue(Cache::has("mvm:waker:favicon:{$server->id}"));
        $this->assertNull(Favicons::toFavicon('not an image'));
    }
}
