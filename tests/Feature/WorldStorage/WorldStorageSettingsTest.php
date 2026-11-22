<?php

namespace Tests\Feature\WorldStorage;

use App\Models\User;
use App\Models\WorldStorageSetting;
use App\WorldStorage\StorageSettings;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\WorldStorage;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use phpseclib3\Crypt\EC;

class WorldStorageSettingsTest extends WorldStorageTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'driver' => 'sftp',
            'host' => 'u123456.your-storagebox.de',
            'port' => 23,
            'username' => 'u123456-sub1',
            'password' => 'correct horse battery',
            'private_key' => '',
            'root' => 'minecraft-worlds',
            'ftps' => true,
            ...$overrides,
        ];
    }

    public function test_players_cant_see_or_change_world_storage(): void
    {
        $player = User::factory()->create();

        // Guests first: the test client stays signed in after actingAs().
        $this->get('/admin/world-storage')->assertRedirect('/login');
        $this->actingAs($player)->get('/admin/world-storage')->assertNotFound();
        $this->actingAs($player)->put('/admin/world-storage', $this->form())->assertNotFound();
        $this->actingAs($player)->post('/admin/world-storage/test', $this->form())->assertNotFound();
        $this->assertSame(0, WorldStorageSetting::query()->count());
    }

    public function test_saving_tests_the_connection_pins_the_host_key_and_encrypts_the_password(): void
    {
        $admin = User::factory()->admin()->create();
        Log::spy();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form())
            ->assertRedirect('/admin/world-storage')
            ->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertSame('u123456.your-storagebox.de', $row->host);
        $this->assertSame(23, $row->port);
        $this->assertSame('correct horse battery', $row->password);
        $this->assertSame(WorldStorage::FAKE_HOST_KEY, $row->host_key);
        $this->assertSame($admin->id, $row->updated_by);

        // Encrypted at rest.
        $raw = DB::table('world_storage_settings')->value('password');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('correct horse', $raw);

        // The log says who, never the secret.
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin): bool {
            $this->assertStringNotContainsString('correct horse', json_encode($context, JSON_THROW_ON_ERROR));

            return $message === 'World storage saved' && $context['by'] === $admin->id;
        })->once();

        $this->assertNotNull(app(WorldStorage::class)->settings());
    }

    public function test_the_page_never_sends_the_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->saveSettings(['private_key' => 'not-really-a-key']);

        $response = $this->actingAs($admin)->get('/admin/world-storage');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/world-storage/Edit')
            ->where('storage.source', 'database')
            ->where('storage.host', 'storage.example.com')
            ->where('storage.has_password', true)
            ->where('storage.has_private_key', true)
            ->where('storage.host_key_fingerprint', StorageSettings::fingerprint(WorldStorage::FAKE_HOST_KEY))
            ->where('problem', null)
            ->missing('storage.password')
            ->missing('storage.private_key'));

        $this->assertStringNotContainsString('hunter2-secret', $response->getContent());
        $this->assertStringNotContainsString('not-really-a-key', $response->getContent());
    }

    public function test_an_empty_password_field_keeps_the_saved_one_and_remove_drops_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->saveSettings();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['host' => 'storage.example.com', 'port' => 22, 'password' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('hunter2-secret', WorldStorageSetting::query()->sole()->password);

        // Nothing left to log in with: refused, and nothing changes.
        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['host' => 'storage.example.com', 'port' => 22, 'password' => '', 'remove_password' => true]))
            ->assertSessionHasErrors(['password' => 'SFTP needs a password or a private key.']);
        $this->assertSame('hunter2-secret', WorldStorageSetting::query()->sole()->password);
    }

    public function test_a_private_key_is_accepted_and_a_public_key_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $key = EC::createKey('Ed25519');

        $this->actingAs($admin)->put('/admin/world-storage', $this->form([
            'password' => '',
            'private_key' => $key->getPublicKey()->toString('OpenSSH'),
        ]))->assertSessionHasErrors(['private_key' => 'That isn\'t an unencrypted OpenSSH or PEM private key.']);

        $this->actingAs($admin)->put('/admin/world-storage', $this->form([
            'password' => '',
            'private_key' => $key->toString('OpenSSH'),
        ]))->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertNull($row->password);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', (string) $row->private_key);
    }

    public function test_a_failed_connection_saves_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['host' => WorldStorage::FAKE_UNREACHABLE_HOST]))
            ->assertSessionHasErrors('connection');

        $this->assertSame(0, WorldStorageSetting::query()->count());
    }

    public function test_a_refused_sign_in_says_so_and_saves_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put('/admin/world-storage', $this->form([
            'port' => 22,
            'username' => 'u123456',
            'password' => WorldStorage::FAKE_WRONG_PASSWORD,
            'root' => 'mc-vps',
        ]))->assertSessionHasErrors('connection');

        $message = (string) session('errors')->first('connection');
        $this->assertStringStartsWith('Not saved. Reached u123456.your-storagebox.de:22, but it refused to sign in user "u123456" with that password.', $message);
        $this->assertStringContainsString('It accepts: publickey, password.', $message);
        $this->assertStringContainsString('"External reachability" must be on', $message);
        $this->assertStringNotContainsString("couldn't write", $message);
        $this->assertStringNotContainsString(WorldStorage::FAKE_WRONG_PASSWORD, $message);
        $this->assertSame(0, WorldStorageSetting::query()->count());
        $response->assertRedirect();
    }

    public function test_a_refused_sign_in_elsewhere_has_no_storage_box_hint(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form([
            'host' => 'storage.example.com',
            'password' => WorldStorage::FAKE_WRONG_PASSWORD,
        ]))->assertSessionHasErrors('connection');

        $message = (string) session('errors')->first('connection');
        $this->assertStringContainsString('refused to sign in user "u123456-sub1"', $message);
        $this->assertStringNotContainsString('Hetzner', $message);
    }

    public function test_test_connection_reports_without_saving(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/admin/world-storage')->post('/admin/world-storage/test', $this->form())
            ->assertRedirect('/admin/world-storage');

        $this->assertSame(0, WorldStorageSetting::query()->count());
    }

    public function test_a_changed_host_key_is_refused_until_trusted(): void
    {
        $admin = User::factory()->admin()->create();
        $other = 'ssh-ed25519 '.base64_encode(str_repeat('x', 51));
        $this->saveSettings(['host_key' => $other]);
        $form = $this->form(['host' => 'storage.example.com', 'port' => 22]);

        $this->actingAs($admin)->put('/admin/world-storage', $form)
            ->assertSessionHasErrors('connection');
        $this->assertSame($other, WorldStorageSetting::query()->sole()->host_key);

        $this->actingAs($admin)->put('/admin/world-storage', [...$form, 'trust_new_host_key' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(WorldStorage::FAKE_HOST_KEY, WorldStorageSetting::query()->sole()->host_key);
    }

    public function test_ftp_needs_a_password_and_keeps_tls_on_by_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['driver' => 'ftp', 'port' => 21, 'password' => '']))
            ->assertSessionHasErrors(['password' => 'FTP needs a password.']);

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['driver' => 'ftp', 'port' => 21]))
            ->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertTrue($row->ftps);
        $this->assertNull($row->host_key);
        $this->assertSame('ftp', StorageSettings::fromModel($row)->diskConfig()['driver']);
        $this->assertTrue(StorageSettings::fromModel($row)->diskConfig()['ssl']);
    }

    public function test_values_that_would_reach_a_shell_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form([
            'host' => 'evil.example.com; rm -rf /',
            'username' => "o'brien",
            'root' => '../etc',
        ]))->assertSessionHasErrors(['host', 'username', 'root']);
    }

    public function test_secrets_saved_under_another_app_key_ask_to_be_entered_again(): void
    {
        $admin = User::factory()->admin()->create();
        $this->saveSettings();

        // Re-encrypt the password with a different key, as if APP_KEY had changed since.
        $other = new Encrypter(random_bytes(32), 'aes-256-cbc');
        DB::table('world_storage_settings')->update(['password' => $other->encryptString('hunter2-secret')]);

        try {
            app(WorldStorage::class)->settings();
            $this->fail('Expected StorageUnavailable.');
        } catch (StorageUnavailable $e) {
            $this->assertStringContainsString('APP_KEY has changed', $e->getMessage());
        }

        $this->actingAs($admin)->get('/admin/world-storage')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('problem', fn (string $problem) => str_contains($problem, 'APP_KEY has changed')));
    }

    public function test_the_env_is_the_fallback_until_something_is_saved(): void
    {
        $admin = User::factory()->admin()->create();
        config(['world_storage.env' => [
            ...config('world_storage.env'),
            'host' => 'env-storage.example.com',
            'username' => 'envuser',
            'password' => 'env-password',
            'host_key' => null,
        ]]);

        // No pinned host key: not used yet, and the page says how to fix it.
        $this->actingAs($admin)->get('/admin/world-storage')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storage.source', 'env')
                ->where('storage.host', 'env-storage.example.com')
                ->where('storage.has_password', true)
                ->where('problem', fn (string $problem) => str_contains($problem, 'host key isn\'t pinned')));

        config(['world_storage.env.host_key' => WorldStorage::FAKE_HOST_KEY]);
        $this->assertSame('env-storage.example.com', app(WorldStorage::class)->settings()->host);

        // Saved settings win from then on.
        $this->saveSettings();
        $this->assertSame('storage.example.com', app(WorldStorage::class)->settings()->host);
    }
}
