<?php

namespace Tests\Feature\Servers;

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * #5526: guests (not signed in) get a start budget per server per day, and a guest start
 * can't take the last free slot. Signed-in players aren't limited by either.
 */
class GuestStartLimitTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        config([
            'minecraft.max_running' => 2,
            'minecraft.guest_starts_per_day' => 2,
            'minecraft.guest_free_slots' => 1,
        ]);
    }

    /**
     * A finished run, as a start earlier in the day leaves it.
     */
    private function pastRun(Server $server, ?User $by = null, ?Carbon $at = null): ServerRun
    {
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = null;
        $run->started_by = $by?->id;
        $run->status = RunStatus::Stopped;
        $run->created_at = $at ?? now();
        $run->save();

        return $run;
    }

    private function activeRun(Server $server, ?User $by = null): ServerRun
    {
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = $server->id;
        $run->started_by = $by?->id;
        $run->status = RunStatus::Online;
        $run->save();

        return $run;
    }

    public function test_guests_get_a_daily_budget_per_server_and_players_are_not_limited(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 18:00:00', 'UTC'));
        $server = Server::factory()->public()->create(['name' => 'Open World', 'slug' => 'open-world']);
        $other = Server::factory()->public()->create(['slug' => 'other-world']);
        $player = User::factory()->create();

        // Earlier today: two guest starts (the budget), plus starts that don't count.
        $this->pastRun($server);
        $this->pastRun($server);
        $this->pastRun($server, $player);
        $this->pastRun($server, null, Carbon::parse('2026-11-22 23:59:00', 'UTC'));
        $this->pastRun($other);

        $this->from('/')->post('/servers/open-world/start')
            ->assertRedirect('/')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Open World has been started by guests 2 times today, the most allowed. Sign in to start it again today.');
        $this->assertSame(0, ServerRun::query()->active()->count());

        // Another public server has its own budget.
        $this->from('/')->post('/servers/other-world/start')
            ->assertInertiaFlash('toast.type', 'success');
        ServerRun::query()->active()->update(['active_server_id' => null, 'status' => RunStatus::Stopped->value]);

        // A signed-in player still starts the guest-spent one.
        $this->actingAs($player)->from('/dashboard')->post('/servers/open-world/start')
            ->assertInertiaFlash('toast.type', 'success');
        $this->assertSame($player->id, ServerRun::query()->active()->sole()->started_by);
    }

    public function test_the_budget_starts_again_the_next_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 23:30:00', 'UTC'));
        Server::factory()->public()->create(['slug' => 'open-world']);
        $server = Server::query()->sole();
        $this->pastRun($server);
        $this->pastRun($server);

        $this->post('/servers/open-world/start')->assertInertiaFlash('toast.type', 'error');

        $this->travelTo(Carbon::parse('2026-11-24 00:01:00', 'UTC'));
        $this->post('/servers/open-world/start')->assertInertiaFlash('toast.type', 'success');

        $run = ServerRun::query()->active()->sole();
        $this->assertNull($run->started_by);
    }

    public function test_a_guest_start_cannot_take_the_last_free_slot(): void
    {
        Server::factory()->public()->create(['name' => 'Open World', 'slug' => 'open-world']);
        $busy = Server::factory()->create(['slug' => 'friends']);
        $player = User::factory()->create();
        $this->activeRun($busy, $player);

        // One of two slots is free: it is kept for signed-in players.
        $this->from('/')->post('/servers/open-world/start')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Sign in to start Open World now: the last free server slot is kept for signed-in players. Or try again when another server has stopped.');
        $this->assertSame(1, ServerRun::query()->active()->count());

        // A signed-in player may take it.
        $this->actingAs($player)->from('/dashboard')->post('/servers/open-world/start')
            ->assertInertiaFlash('toast.type', 'success');
        $this->assertSame(2, ServerRun::query()->active()->count());
    }

    public function test_with_no_free_slots_kept_guests_may_use_any_free_slot(): void
    {
        config(['minecraft.guest_free_slots' => 0]);
        Server::factory()->public()->create(['slug' => 'open-world']);
        $this->activeRun(Server::factory()->create(['slug' => 'friends']), User::factory()->create());

        $this->post('/servers/open-world/start')->assertInertiaFlash('toast.type', 'success');
        $this->assertSame(2, ServerRun::query()->active()->count());
    }

    public function test_a_budget_of_zero_means_guests_cannot_start(): void
    {
        config(['minecraft.guest_starts_per_day' => 0]);
        Server::factory()->public()->create(['name' => 'Open World', 'slug' => 'open-world']);

        $this->post('/servers/open-world/start')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', "Sign in to start Open World: guests can't start servers.");
        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_the_share_link_counts_against_the_same_budget(): void
    {
        $server = Server::factory()->linkOnly()->create(['name' => 'Community SMP', 'slug' => 'community-smp']);
        $this->pastRun($server);
        $this->pastRun($server);
        $path = '/s/'.(string) $server->share_token;

        $this->from($path)->post($path.'/start')
            ->assertRedirect($path)
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Community SMP has been started by guests 2 times today, the most allowed. Sign in to start it again today.');
        $this->assertSame(0, ServerRun::query()->active()->count());
    }

    public function test_the_admin_server_form_shows_the_limits(): void
    {
        $admin = User::factory()->create(['email' => 'limits-admin@example.com']);
        $admin->role = Role::Admin;
        $admin->save();
        $server = Server::factory()->public()->create(['slug' => 'open-world']);
        $this->pastRun($server);

        $this->actingAs($admin)->get('/admin/servers/open-world/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('guestStarts.per_day', 2)
                ->where('guestStarts.free_slots', 1)
                ->where('guestStarts.max_running', 2)
                ->where('guestStarts.used_today', 1));

        $this->actingAs($admin)->get('/admin/servers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('guestStarts.per_day', 2)
                ->where('guestStarts.used_today', null));
    }
}
