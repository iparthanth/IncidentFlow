<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Enums\OrganizationRole;
use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Postmortem;
use App\Models\Service;
use App\Policies\IncidentPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PostmortemPolicy;
use App\Policies\ServicePolicy;
use App\Services\Auth\KeyProvider;
use App\Services\Auth\TokenService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Realtime\RealtimePublisher;
use App\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Psr\Log\LoggerInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestContext::class, static fn (): RequestContext => new RequestContext);

        $this->app->singleton(KeyProvider::class, static fn ($app): KeyProvider => new KeyProvider(
            $app['config']->get('jwt'),
        ));

        $this->app->singleton(TokenService::class, static fn ($app): TokenService => new TokenService(
            $app->make(KeyProvider::class),
            $app->make(CacheFactory::class),
            $app['config']->get('jwt'),
        ));

        $this->app->singleton(RealtimePublisher::class, static fn ($app): RealtimePublisher => new RealtimePublisher(
            $app->make(RedisFactory::class),
            $app->make(LoggerInterface::class),
            (string) $app['config']->get('realtime.connection'),
            (string) $app['config']->get('realtime.channel_prefix'),
            (bool) $app['config']->get('realtime.enabled'),
        ));

        $this->app->singleton(NotificationDispatcher::class, static fn ($app): NotificationDispatcher => new NotificationDispatcher(
            (int) $app['config']->get('incidents.notifications.stale_after_minutes', 5),
        ));
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuth();
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->configureUrls();
    }

    /**
     * Password policy, NIST-aligned: length first, no composition theatre.
     *
     * `uncompromised()` checks the password against the Have I Been Pwned
     * k-anonymity API — genuinely valuable, and a network call. Enabling it
     * outside deployed environments would make the test suite fail on a plane
     * and make local development depend on a third party being reachable, so
     * it is switched on where it protects real accounts and off where it only
     * adds flakiness.
     */
    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->max(255);

            return $this->app->environment(['production', 'staging'])
                ? $rule->uncompromised()
                : $rule;
        });
    }

    /**
     * Strict mode, on purpose.
     *
     * `preventLazyLoading` turns an accidental N+1 into a loud failure in
     * development and CI instead of a page that quietly issues 200 queries in
     * production. `preventSilentlyDiscardingAttributes` turns "I forgot to add
     * that field to $fillable" into an exception rather than a save that looks
     * successful and drops the value — the kind of bug that only surfaces when
     * a user asks why their edit did not stick.
     *
     * Production keeps the two guards that prevent data loss but drops the
     * lazy-loading guard: an N+1 is a performance bug, and taking a page down
     * over it during an incident would be the wrong trade.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            Model::preventSilentlyDiscardingAttributes();
        }
    }

    private function configureAuth(): void
    {
        Auth::extend('jwt', static fn ($app, string $name, array $config): JwtGuard => new JwtGuard(
            Auth::createUserProvider($config['provider'] ?? null),
            $app->make(Request::class),
            $app->make(TokenService::class),
        ));

        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(IncidentComment::class, IncidentPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Postmortem::class, PostmortemPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        /**
         * Member management checks are authorized against the membership row,
         * not the organization, so the same policy has to be reachable from
         * both types. Without this mapping `Gate::authorize('removeMember', $member)`
         * finds no policy and denies — which looks like working authorization
         * while actually meaning "the rule never ran".
         */
        Gate::policy(OrganizationMember::class, OrganizationPolicy::class);

        // Horizon's dashboard is gated on being an administrator somewhere.
        Gate::define('viewHorizon', static fn ($user): bool => $user->memberships()
            ->where('role', OrganizationRole::Administrator->value)
            ->exists());
    }

    /**
     * Rate limits, sized to what each endpoint is for.
     *
     * The interesting one is `auth`: login is limited per *IP and email
     * together*. Limiting by IP alone lets an attacker spray one password
     * across thousands of accounts from a single address; limiting by email
     * alone lets anyone lock a known user out of their own account. Keying on
     * both bounds credential stuffing without handing out a denial-of-service
     * primitive against named users.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => $request->user()
            ? Limit::perMinute(180)->by('user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute(40)->by('ip:'.$request->ip()));

        RateLimiter::for('auth', static fn (Request $request): array => [
            Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth-id:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        // Writes are cheap to issue and expensive to serve: each one writes a
        // timeline event, an audit row, notification rows, and a publish.
        RateLimiter::for('writes', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('write:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A CSV export streams the whole incident table. Nobody needs it often.
        RateLimiter::for('exports', static fn (Request $request): Limit => Limit::perHour(10)
            ->by('export:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Stream tickets are one per connection, but reconnect storms after a
        // deploy are real, so this ceiling is generous rather than tight.
        RateLimiter::for('realtime', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('rt:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function configureUrls(): void
    {
        // Behind a TLS-terminating proxy, generated URLs must still be https
        // or every emailed link downgrades the recipient to plaintext.
        if ($this->app->environment('production', 'staging')) {
            URL::forceScheme('https');
        }
    }
}
