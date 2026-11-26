<?php

namespace App\Providers;

use App\Contracts\PushGateway;
use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use App\Services\Gateways\HttpSmsGateway;
use App\Services\Gateways\HttpWhatsAppGateway;
use App\Services\Gateways\LogPushGateway;
use App\Services\Gateways\LogSmsGateway;
use App\Services\Gateways\LogWhatsAppGateway;
use App\Support\GroupContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GroupContext::class, fn () => new GroupContext);

        $this->app->bind(SmsGateway::class, function () {
            return config('services.sms.driver') === 'http'
                ? new HttpSmsGateway
                : new LogSmsGateway;
        });

        $this->app->bind(PushGateway::class, fn () => new LogPushGateway);

        $this->app->bind(WhatsAppGateway::class, function () {
            return config('services.whatsapp.driver') === 'http'
                ? new HttpWhatsAppGateway
                : new LogWhatsAppGateway;
        });
    }

    public function boot(): void
    {
        $this->configureGates();
        $this->configureRateLimiting();
        $this->configurePasswordRules();
        $this->configureBlade();
        $this->configureTrustedProxies();

        if ($this->app->environment('production')) {
            $this->assertSafeProductionEnvironment();
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }

    /**
     * Fail closed if a local/debug configuration is deployed by mistake.
     */
    private function assertSafeProductionEnvironment(): void
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false';
        }

        $key = (string) config('app.key');
        if ($key === '' || str_contains($key, 'REPLACE') || strlen($key) < 40) {
            $problems[] = 'APP_KEY must be a freshly generated key';
        }

        if (filter_var(env('CHAMA_SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $problems[] = 'CHAMA_SEED_DEMO must be false';
        }

        if ($problems !== []) {
            throw new \RuntimeException('Unsafe production configuration: '.implode('; ', $problems).'.');
        }
    }

    /**
     * Reverse proxies (Caddy, Traefik, a host Nginx) terminate TLS before the
     * request reaches this app. Without trusting their forwarded headers
     * Laravel would generate http:// URLs and drop secure cookies.
     *
     * This runs in the provider because config is not bound yet when the
     * middleware callbacks in bootstrap/app.php are evaluated.
     */
    private function configureTrustedProxies(): void
    {
        $trustedProxies = config('app.trusted_proxies');

        if (is_string($trustedProxies) && $trustedProxies !== '') {
            TrustProxies::at(
                $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies))
            );
        }
    }

    private function configureGates(): void
    {
        // Platform administrators manage the platform, not tenant financial
        // records. Only platform-level abilities bypass tenant policies.
        Gate::before(function (User $user, string $ability) {
            if (! $user->isSuperAdmin()) {
                return null;
            }

            return in_array($ability, ['manage-platform', 'view-platform'], true) ? true : null;
        });

        Gate::define('access-group', function (User $user, Group $group) {
            return $user->memberships()
                ->where('group_id', $group->id)
                ->whereIn('status', ['active', 'approved'])
                ->exists();
        });

        Gate::define('access-member', function (User $user, Member $member) {
            return app(GroupContext::class)->canAccessMember($member);
        });

        Gate::define('manage-platform', fn (User $user) => $user->isSuperAdmin());

        Gate::define('staff', fn (User $user) => app(GroupContext::class)->isStaff());
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                // Per-account cap survives distributed attempts from many IPs.
                Limit::perMinutes(15, 10)->by($email),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(5)->by((string) $request->input('token').'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }

    /**
     * Currency, rate and multiplier formatting used across Blade views. Keeping
     * the formatting in one directive stops views from inventing their own
     * rounding rules for stored minor units.
     */
    private function configureBlade(): void
    {
        Blade::directive('money', fn (string $expression) => "<?php echo e(\\App\\Support\\Money::format({$expression})); ?>");
        Blade::directive('bps', fn (string $expression) => "<?php echo e(\\App\\Support\\Money::percentFromBps((int) ({$expression}))); ?>");
        Blade::directive('multiplier', fn (string $expression) => "<?php echo e(\\App\\Support\\Money::multiplier((int) ({$expression}))); ?>");
    }

    private function configurePasswordRules(): void
    {
        Password::defaults(function () {
            return $this->app->isProduction()
                ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
                : Password::min(8)->letters()->numbers();
        });
    }
}
