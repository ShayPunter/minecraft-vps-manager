<?php

namespace Tests\Feature\Servers;

use App\Enums\RunStatus;
use App\Minecraft\Software;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\BootEstimate;
use App\Servers\ServerPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Usually ready in about N min" and "About N min left" (#5379).
 */
class BootEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
    }

    /**
     * An ended run that took $seconds from Start to Online ($seconds null: never online).
     */
    private function pastRun(Server $server, ?int $seconds, ?int $worldArchiveId = 1, int $hoursAgo = 1): ServerRun
    {
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->status = RunStatus::Stopped;
        $run->created_at = now()->subHours($hoursAgo);
        $run->online_at = $seconds === null ? null : now()->subHours($hoursAgo)->addSeconds($seconds);
        $run->ended_at = now()->subHours($hoursAgo)->addHour();
        $run->world_archive_id = $worldArchiveId;
        $run->save();

        return $run;
    }

    public function test_the_median_of_odd_and_even_lists(): void
    {
        $this->assertNull(BootEstimate::median([]));
        $this->assertSame(120, BootEstimate::median([300, 60, 120]));
        $this->assertSame(150, BootEstimate::median([300, 60, 120, 180]));
    }

    public function test_it_takes_the_median_of_the_last_five_starts_that_came_online(): void
    {
        $server = Server::factory()->create();
        // Older starts beyond the newest five don't count.
        $this->pastRun($server, 1800, hoursAgo: 10);
        $this->pastRun($server, 1800, hoursAgo: 9);
        foreach ([200, 100, 400, 300, 500] as $i => $seconds) {
            $this->pastRun($server, $seconds, hoursAgo: 5 - $i);
        }
        // A start that never came online doesn't either.
        $this->pastRun($server, null, hoursAgo: 0);

        $this->assertSame(300, BootEstimate::typicalSeconds($server));
    }

    public function test_installs_and_ordinary_starts_are_counted_apart(): void
    {
        $paper = Server::factory()->create(['software' => Software::Paper->value]);
        $this->pastRun($paper, 900, worldArchiveId: null, hoursAgo: 3); // the first start installed Paper
        $this->pastRun($paper, 120, hoursAgo: 2);
        $this->pastRun($paper, 180, hoursAgo: 1);

        // It has a saved world now, so the next start is an ordinary one.
        $paper->current_world_archive_id = 7;
        $paper->save();
        $this->assertFalse(BootEstimate::installs($paper));
        $this->assertSame(150, BootEstimate::typicalSeconds($paper));

        // A new Paper server installs on its first start: it gets the install time.
        $fresh = Server::factory()->create(['software' => Software::Paper->value]);
        $this->assertTrue(BootEstimate::installs($fresh));
        $this->assertSame(900, BootEstimate::typicalSeconds($fresh));

        // A custom server's start with no saved world isn't an install.
        $custom = Server::factory()->create();
        $this->pastRun($custom, 240, worldArchiveId: null);
        $this->assertFalse(BootEstimate::installs($custom));
        $this->assertSame(240, BootEstimate::typicalSeconds($custom));
    }

    public function test_without_its_own_history_it_uses_all_servers_else_nothing(): void
    {
        $new = Server::factory()->create();
        $this->assertNull(BootEstimate::typicalSeconds($new));

        $other = Server::factory()->create();
        $this->pastRun($other, 60);
        $this->pastRun($other, 180);
        $this->pastRun($other, 240);

        $this->assertSame(180, BootEstimate::typicalSeconds($new));
    }

    public function test_the_pages_get_the_estimate_while_stopped_and_the_time_left_while_starting(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);
        $this->pastRun($server, 240);

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.typical_boot_seconds', 240)
            ->where('servers.0.boot_elapsed_seconds', null));

        // A start that began 90 s ago.
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = $server->id;
        $run->status = RunStatus::Booting;
        $run->created_at = now()->subSeconds(90);
        $run->save();

        $presented = ServerPresenter::one($server->refresh()->load('activeRun', 'latestRun'), $admin);
        $this->assertSame(240, $presented['typical_boot_seconds']);
        $this->assertSame(90, $presented['boot_elapsed_seconds']);

        // Online: neither.
        $run->status = RunStatus::Online;
        $run->online_at = now();
        $run->save();
        $presented = ServerPresenter::one($server->refresh()->load('activeRun', 'latestRun'), $admin);
        $this->assertNull($presented['typical_boot_seconds']);
        $this->assertNull($presented['boot_elapsed_seconds']);
    }
}
