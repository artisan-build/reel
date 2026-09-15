<?php

namespace App\Providers;

use App\Models\Application;
use App\Services\ReelEnrollmentScopeResolver;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\Contracts\ResolvesAsymmetricEnrollmentScope;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(ResolvesAsymmetricEnrollmentScope::class, ReelEnrollmentScopeResolver::class);
        $this->app->scoped(IdentityContext::class, function (): IdentityContext {
            $user = request()->user();
            abort_unless($user instanceof User, 403);

            return DomainIdentityContext::forUser($user, InstallationAuthority::current());
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureCacheStoreIsConfigured();
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /** Ensure scheduler mutexes cannot silently use Laravel's null cache store. */
    private function ensureCacheStoreIsConfigured(): void
    {
        if (app()->runningInConsole() && (\Illuminate\Support\Facades\Request::server('argv')[1] ?? null) === 'package:discover') {
            return;
        }

        $store = config('cache.default');
        $stores = config('cache.stores', []);

        if (! is_string($store) || $store === '' || ! is_array($stores) || ! array_key_exists($store, $stores)) {
            throw new RuntimeException('CACHE_STORE must name a configured cache store.');
        }
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

    private function configureRateLimiting(): void
    {
        RateLimiter::for('reel-ingest', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }
}
