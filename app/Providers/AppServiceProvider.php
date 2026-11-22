<?php

namespace App\Providers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Cloud\HetznerCloud;
use App\Servers\FakeMinecraftProbe;
use App\Servers\MinecraftProbe;
use App\Servers\TcpMinecraftProbe;
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
                sshPublicKeyPath: (string) config('services.hetzner.ssh_public_key_path'),
            );
        });

        $this->app->singleton(MinecraftProbe::class, function ($app): MinecraftProbe {
            if ($app->environment('testing', 'e2e') || config('services.hetzner.fake') === true) {
                return new FakeMinecraftProbe($app->make(CacheRepository::class));
            }

            return new TcpMinecraftProbe;
        });
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
