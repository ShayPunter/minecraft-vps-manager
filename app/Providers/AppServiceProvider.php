<?php

namespace App\Providers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Cloud\HetznerCloud;
use App\Dns\CloudflareDns;
use App\Dns\DnsProvider;
use App\Dns\FakeDnsProvider;
use App\Minecraft\FakePlayers;
use App\Minecraft\FakeVersions;
use App\Minecraft\Modrinth\FakeModrinth;
use App\Minecraft\Modrinth\ModLoaders;
use App\Minecraft\Modrinth\ModrinthSource;
use App\Minecraft\Modrinth\OnlineModrinth;
use App\Minecraft\MojangPlayers;
use App\Minecraft\OnlineVersions;
use App\Minecraft\PlayerLookup;
use App\Minecraft\VersionSource;
use App\Servers\FakeMinecraftProbe;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\SshRemoteShell;
use App\Servers\TcpMinecraftProbe;
use App\WorldStorage\WorldStorage;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CloudProvider::class, function ($app): CloudProvider {
            // Tests and E2E never reach the real API, whatever the env says: a server costs money.
            if ($app->environment('testing', 'e2e') || config('services.hetzner.fake') === true) {
                // In E2E a fake VPS boots by itself on the next check, like a real one would.
                return new FakeCloudProvider($app->make(CacheRepository::class), autoBoot: $app->environment('e2e'));
            }

            $token = config('services.hetzner.token');

            return new HetznerCloud(
                token: is_string($token) ? $token : null,
                baseUrl: (string) config('services.hetzner.base_url'),
            );
        });

        $this->app->singleton(MinecraftProbe::class, function ($app): MinecraftProbe {
            if ($app->environment('testing', 'e2e') || config('services.hetzner.fake') === true) {
                return new FakeMinecraftProbe($app->make(CacheRepository::class));
            }

            return new TcpMinecraftProbe;
        });

        $this->app->singleton(RemoteShell::class, function ($app): RemoteShell {
            if ($app->environment('testing', 'e2e') || config('services.hetzner.fake') === true) {
                return new FakeRemoteShell($app->make(CacheRepository::class));
            }

            return new SshRemoteShell((string) config('services.hetzner.legacy_ssh_private_key_path'));
        });

        // Tests and E2E never call Cloudflare (#5338).
        $this->app->singleton(DnsProvider::class, function ($app): DnsProvider {
            if ($app->environment('testing', 'e2e') || config('services.cloudflare.fake') === true) {
                return new FakeDnsProvider($app->make(CacheRepository::class));
            }

            return new CloudflareDns((string) config('services.cloudflare.base_url'));
        });

        // Tests and E2E never ask Mojang, PaperMC or Fabric (#5351).
        $this->app->singleton(VersionSource::class, function ($app): VersionSource {
            if ($app->environment('testing', 'e2e')) {
                return new FakeVersions($app->make(CacheRepository::class));
            }

            return new OnlineVersions;
        });

        // Tests and E2E never ask Modrinth or the loaders' Mavens (#5352).
        $this->app->singleton(ModrinthSource::class, function ($app): ModrinthSource {
            if ($app->environment('testing', 'e2e')) {
                return new FakeModrinth($app->make(CacheRepository::class));
            }

            return new OnlineModrinth(new ModLoaders($app->make(VersionSource::class)));
        });

        // Tests and E2E never ask Mojang about player names (#5377).
        $this->app->singleton(PlayerLookup::class, fn ($app): PlayerLookup => $app->environment('testing', 'e2e')
            ? new FakePlayers
            : new MojangPlayers);

        // Tests and E2E keep worlds in a local folder, whatever the settings say (#5288).
        $this->app->singleton(WorldStorage::class, fn ($app): WorldStorage => new WorldStorage(
            fake: $app->environment('testing', 'e2e') || config('world_storage.fake') === true,
            fakeRoot: (string) config('world_storage.fake_root'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Start creates a VPS (real money): a few a minute per person, or per IP for guests.
        RateLimiter::for('server-start', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->user()?->id !== null ? 'user:'.$request->user()->id : 'ip:'.$request->ip()));

        // A run's log shipper posts about every 5 seconds (#5367).
        RateLimiter::for('run-log', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('run:'.(string) $request->route('run')));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
