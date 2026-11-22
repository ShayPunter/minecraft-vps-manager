<?php

namespace App\Providers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Cloud\HetznerCloud;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
                return new FakeCloudProvider($app->make(CacheRepository::class));
            }

            $token = config('services.hetzner.token');

            return new HetznerCloud(
                token: is_string($token) ? $token : null,
                baseUrl: (string) config('services.hetzner.base_url'),
                sshPublicKeyPath: (string) config('services.hetzner.ssh_public_key_path'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
