<?php

namespace Tests\Feature\Dns;

use App\Dns\DnsProvider;
use App\Dns\FakeDnsProvider;
use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Admin → DNS (#5338) and the server form's Subdomain field.
 */
class DnsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return ['domain' => 'Example.com.', 'zone_id' => strtoupper(self::ZONE), 'api_token' => 'cf_Token-123', ...$overrides];
    }

    private function fake(): FakeDnsProvider
    {
        $dns = $this->app->make(DnsProvider::class);
        $this->assertInstanceOf(FakeDnsProvider::class, $dns);

        return $dns;
    }

    public function test_players_and_guests_cant_see_or_change_it(): void
    {
        $this->get('/admin/dns')->assertRedirect('/login');
        $player = User::factory()->create();
        $this->actingAs($player)->get('/admin/dns')->assertNotFound();
        $this->actingAs($player)->put('/admin/dns', $this->form())->assertNotFound();
        $this->assertSame(0, DnsSetting::query()->count());
    }

    public function test_saving_tests_the_token_encrypts_it_and_never_shows_it(): void
    {
        $admin = User::factory()->admin()->create();
        Log::spy();

        $this->actingAs($admin)->put('/admin/dns', $this->form())
            ->assertRedirect('/admin/dns')
            ->assertSessionHasNoErrors();

        $row = DnsSetting::query()->sole();
        $this->assertSame('example.com', $row->domain);
        $this->assertSame(self::ZONE, $row->zone_id);
        $this->assertSame('cf_Token-123', $row->api_token);
        $this->assertStringNotContainsString('cf_Token-123', (string) DB::table('dns_settings')->value('api_token'));
        $this->assertSame([], $this->fake()->records(self::ZONE), 'The test record is deleted again.');

        $this->actingAs($admin)->get('/admin/dns')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/dns/Edit')
                ->where('dns.domain', 'example.com')
                ->where('dns.has_token', true)
                ->where('problem', null)
                ->missing('dns.api_token'))
            ->assertDontSee('cf_Token-123');

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'DNS settings saved'
            && ! str_contains(json_encode($context), 'cf_Token-123'));
    }

    public function test_an_empty_token_keeps_the_saved_one(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/dns', $this->form())->assertSessionHasNoErrors();

        $this->actingAs($admin)->put('/admin/dns', $this->form(['api_token' => '', 'domain' => 'example.com']))
            ->assertSessionHasNoErrors();
        $this->assertSame('cf_Token-123', DnsSetting::query()->sole()->api_token);
    }

    public function test_a_first_save_needs_a_token(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/dns', $this->form(['api_token' => '']))
            ->assertSessionHasErrors(['api_token' => 'Paste the API token.']);
    }

    public function test_a_refused_token_saves_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/dns', $this->form(['api_token' => FakeDnsProvider::FAKE_BAD_TOKEN]))
            ->assertSessionHasErrors(['connection' => "Not saved. Couldn't make a test record in that zone: Cloudflare refused the token: Authentication error (code 10000)"]);
        $this->assertSame(0, DnsSetting::query()->count());
    }

    public function test_a_zone_id_of_another_domain_is_refused(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/dns', $this->form(['zone_id' => FakeDnsProvider::FAKE_OTHER_ZONE_ID]))
            ->assertSessionHasErrors('connection');

        $this->assertStringContainsString("That zone id doesn't belong to example.com", (string) session('errors')->first('connection'));
        $this->assertSame(0, DnsSetting::query()->count());
        $this->assertSame([], $this->fake()->records(FakeDnsProvider::FAKE_OTHER_ZONE_ID));
    }

    public function test_bad_input_is_refused_before_calling_cloudflare(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/dns', $this->form(['domain' => 'not a domain', 'zone_id' => 'xyz', 'api_token' => 'has spaces in it']))
            ->assertSessionHasErrors(['domain', 'zone_id', 'api_token']);
    }

    private function saveDns(): void
    {
        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = self::ZONE;
        $row->api_token = 'cf_Token-123';
        $row->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function serverForm(array $overrides = []): array
    {
        return [
            'name' => 'All the Mods 9', 'slug' => 'atm9', 'server_type' => 'cx33', 'location' => 'fsn1',
            'start_command' => 'java -jar server.jar nogui', 'java_version' => 21, 'idle_minutes' => 15,
            ...$overrides,
        ];
    }

    public function test_a_subdomain_is_saved_lowercased_and_a_full_name_is_cut_to_the_part_before_the_domain(): void
    {
        $this->saveDns();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['subdomain' => ' MC.ATM9.example.com. ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('mc.atm9', Server::query()->sole()->subdomain);

        $this->actingAs($admin)->get('/admin/servers/atm9/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('server.subdomain', 'mc.atm9')->where('dnsDomain', 'example.com'));
        $this->actingAs($admin)->get('/admin/dns')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('servers.0.address', 'mc.atm9.example.com'));

        // Clearing it.
        $this->actingAs($admin)->put('/admin/servers/atm9', $this->serverForm(['subdomain' => '']))->assertSessionHasNoErrors();
        $this->assertNull(Server::query()->sole()->subdomain);
    }

    public function test_a_subdomain_is_unique_and_well_formed_and_never_the_apps_own_address(): void
    {
        $this->saveDns();
        config(['app.url' => 'https://minecraft.example.com']);
        $admin = User::factory()->admin()->create();
        Server::factory()->create(['slug' => 'taken', 'subdomain' => 'atm9']);

        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['subdomain' => 'atm9']))
            ->assertSessionHasErrors(['subdomain' => 'Another server already uses that name.']);
        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['subdomain' => 'bad_name']))
            ->assertSessionHasErrors('subdomain');
        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['subdomain' => '-atm9']))
            ->assertSessionHasErrors('subdomain');
        $this->actingAs($admin)->post('/admin/servers', $this->serverForm(['subdomain' => 'minecraft']))
            ->assertSessionHasErrors(['subdomain' => "minecraft.example.com is this app's own address. Pick another name."]);

        // Editing the server that has it keeps it.
        $this->actingAs($admin)->put('/admin/servers/taken', $this->serverForm(['slug' => 'taken', 'subdomain' => 'atm9']))
            ->assertSessionHasNoErrors();
    }
}
