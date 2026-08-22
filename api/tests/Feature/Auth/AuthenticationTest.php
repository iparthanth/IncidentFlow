<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\OrganizationRole;
use App\Exceptions\InvalidTokenException;
use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_the_user_organization_and_membership_together(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'organization_name' => 'Analytical Engines',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'ada@example.com');
        $this->assertIsString($response->json('data.access_token'));

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $organization = Organization::query()->where('name', 'Analytical Engines')->firstOrFail();

        // Whoever creates the tenant must administer it, or there is nobody
        // able to invite the second person.
        $this->assertSame(OrganizationRole::Administrator, $user->roleIn($organization));
    }

    public function test_registration_rejects_a_breached_or_short_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'organization_name' => 'Analytical Engines',
        ]);

        $this->assertApiError($response, 422, 'validation_failed');
        $response->assertJsonPath('error.details.fields.password.0', fn ($message) => is_string($message));
    }

    public function test_registration_normalises_email_case(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'Ada@Example.COM',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'organization_name' => 'Engines',
        ])->assertCreated();

        // Otherwise Ada@example.com and ada@example.com are two accounts, and
        // the "email already taken" check silently stops working.
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_login_returns_an_access_token_and_sets_an_httponly_refresh_cookie(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'grace@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertIsString($response->json('data.access_token'));

        $cookieName = (string) config('jwt.refresh_cookie.name');
        $cookie = $response->getCookie($cookieName, decrypt: false);

        $this->assertNotNull($cookie, 'The refresh cookie must be set on login.');
        // The whole point: an XSS payload can read anything JavaScript can.
        $this->assertTrue($cookie->isHttpOnly(), 'The refresh cookie must be HttpOnly.');
        $this->assertSame('strict', $cookie->getSameSite());

        $this->assertDatabaseCount('refresh_tokens', 1);
        // Only the hash is stored, so a database dump yields no usable session.
        $this->assertNotSame($cookie->getValue(), RefreshToken::query()->first()?->token_hash);
    }

    public function test_login_gives_an_identical_answer_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ]);

        // Anything else turns the login form into a user directory.
        $this->assertApiError($wrongPassword, 401, 'invalid_credentials');
        $this->assertApiError($unknownEmail, 401, 'invalid_credentials');
        $this->assertSame($wrongPassword->json('error.message'), $unknownEmail->json('error.message'));
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'former@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'former@example.com',
            'password' => 'password',
        ]);

        $this->assertApiError($response, 403, 'account_disabled');
    }

    public function test_a_valid_access_token_reaches_a_protected_route(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_request_without_a_token_is_rejected_as_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/incidents');

        $this->assertApiError($response, 401, 'unauthenticated');
    }

    public function test_an_expired_token_reports_token_expired_so_the_client_knows_to_refresh(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // Distinguishing expired from invalid is what lets the frontend
        // silently refresh instead of dumping the user on the login screen.
        // Comfortably beyond jwt.leeway, which deliberately tolerates a little
        // clock drift between the API, the realtime node and the client.
        config()->set('jwt.ttl.access', -120);
        // The service captures its config at construction, so the singleton has
        // to be rebuilt for the override to take effect.
        $this->app->forgetInstance(TokenService::class);

        $token = app(TokenService::class)->issueAccessToken($user);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->token,
            'X-Organization' => $organization->slug,
        ])->getJson('/api/v1/auth/me');

        $this->assertApiError($response, 401, 'token_expired');
    }

    public function test_a_realtime_ticket_is_not_accepted_as_an_api_token(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        $ticket = app(TokenService::class)->issueRealtimeTicket($user, $organization, OrganizationRole::Responder);

        // The audience check is what stops a stream credential — which travels
        // in a query string and lands in access logs — from being replayed
        // against the API.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$ticket->token,
            'X-Organization' => $organization->slug,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_refresh_rotates_the_token_and_invalidates_the_old_one(): void
    {
        $user = User::factory()->create();
        $tokens = app(TokenService::class);
        $issued = $tokens->issueRefreshToken($user);

        $result = $tokens->rotateRefreshToken($issued['plain']);

        $this->assertNotSame($issued['plain'], $result['refresh']['plain']);
        $this->assertNotNull($issued['model']->fresh()?->revoked_at);
        $this->assertSame('rotated', $issued['model']->fresh()?->revoked_reason);
    }

    public function test_replaying_a_rotated_refresh_token_revokes_the_whole_family(): void
    {
        $user = User::factory()->create();
        $tokens = app(TokenService::class);

        $first = $tokens->issueRefreshToken($user);
        $second = $tokens->rotateRefreshToken($first['plain']);

        // The attacker (or the victim) presents the already-used token. We
        // cannot tell which, so both are logged out.
        try {
            $tokens->rotateRefreshToken($first['plain']);
            $this->fail('Reusing a rotated refresh token should have been rejected.');
        } catch (InvalidTokenException $e) {
            $this->assertSame('reused', $e->reason);
        }

        $this->assertNotNull(
            $second['refresh']['model']->fresh()?->revoked_at,
            'The descendant token must be revoked when reuse is detected.',
        );
        $this->assertSame(0, RefreshToken::query()->usable()->count());
    }

    public function test_logout_denylists_the_access_token_for_its_remaining_life(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $token = app(TokenService::class)->issueAccessToken($user);

        $headers = [
            'Authorization' => 'Bearer '.$token->token,
            'X-Organization' => $organization->slug,
        ];

        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();

        /**
         * Two HTTP calls share one container in a test, and AuthManager caches
         * its guards — so the second request would otherwise reuse the guard
         * that already resolved this user and never re-check the token. In
         * production every request boots a fresh container, so this only needs
         * saying here.
         */
        Auth::forgetGuards();

        // Without the denylist, "sign out" would remain a suggestion for the
        // rest of the token's fifteen minutes.
        $response = $this->withHeaders($headers)->getJson('/api/v1/auth/me');
        $this->assertApiError($response, 401, 'token_revoked');
    }

    public function test_logout_everywhere_revokes_every_session(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $tokens = app(TokenService::class);

        $tokens->issueRefreshToken($user);
        $tokens->issueRefreshToken($user);

        $this->actingAsMember($user, $organization)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('data.sessions_revoked', 2);

        $this->assertSame(0, RefreshToken::query()->usable()->count());
    }

    public function test_repeated_failed_sign_ins_for_one_identity_are_throttled(): void
    {
        /*
         * There was no test for this at all: the limiters were defined and
         * attached to the routes, which is easy to confirm by reading, and
         * nothing proved they actually refuse anything.
         *
         * The identity limit is deliberately keyed on email *and* IP together.
         * Keyed on IP alone an attacker sprays one password across thousands of
         * accounts from one address; keyed on email alone anyone can lock a
         * named user out of their own account on demand.
         */
        /*
         * Reset the limiter before counting. Its buckets live in the cache, and
         * the cache is per-test locally (CACHE_STORE=array in phpunit.xml) but
         * shared for the whole run on CI (CACHE_STORE=redis). Nine auth
         * requests happen in this file before this test, so on CI the per-IP
         * budget of 10 was nearly spent and the first attempt here already came
         * back 429 -- the assertion below then failed for a reason that had
         * nothing to do with what it is testing.
         */
        Cache::flush();

        $limit = (int) config('incidents.rate_limits.auth_per_identity');

        $statuses = [];
        for ($attempt = 0; $attempt <= $limit; $attempt++) {
            $statuses[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@incidentflow.test',
                'password' => 'not-the-password',
            ])->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, $limit), 'Throttling must not begin before the configured limit.');
        $this->assertSame(429, end($statuses), 'The attempt past the limit must be refused with 429.');
    }

    public function test_a_throttled_response_says_when_to_retry(): void
    {
        /*
         * Reset the limiter before counting. Its buckets live in the cache, and
         * the cache is per-test locally (CACHE_STORE=array in phpunit.xml) but
         * shared for the whole run on CI (CACHE_STORE=redis). Nine auth
         * requests happen in this file before this test, so on CI the per-IP
         * budget of 10 was nearly spent and the first attempt here already came
         * back 429 -- the assertion below then failed for a reason that had
         * nothing to do with what it is testing.
         */
        Cache::flush();

        $limit = (int) config('incidents.rate_limits.auth_per_identity');

        $response = null;
        for ($attempt = 0; $attempt <= $limit; $attempt++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'someone-else@incidentflow.test',
                'password' => 'not-the-password',
            ]);
        }

        $response->assertStatus(429);
        // Without Retry-After a client can only guess, and guessing means
        // hammering an endpoint that is already refusing it.
        $response->assertHeader('Retry-After');
    }
}
