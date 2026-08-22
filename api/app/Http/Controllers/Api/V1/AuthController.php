<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\JwtGuard;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\IssuedToken;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration, login, token refresh and logout.
 *
 * The token strategy in one paragraph: the client holds a 15-minute access
 * token **in memory only** and a 30-day refresh token in an HttpOnly cookie it
 * cannot read. XSS therefore cannot exfiltrate the long-lived credential, and
 * the short-lived one dies with the tab. Refresh tokens rotate on every use,
 * so a stolen one is detectable — presenting a token that was already rotated
 * revokes the entire family.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Creates the account and its first organization together.
     *
     * They are one transaction because a user with no organization cannot do
     * anything at all — a half-registered account is not a lesser state, it is
     * a broken one.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$user, $organization] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $organization = Organization::query()->create([
                'name' => $validated['organization_name'],
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            OrganizationMember::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                // Whoever creates the tenant administers it; otherwise there
                // is nobody able to invite the second person.
                'role' => OrganizationRole::Administrator,
                'joined_at' => now(),
            ]);

            $this->audit->record('auth.registered', $user, $user, $organization->getKey());

            return [$user, $organization];
        });

        return $this->issueSession($request, $user, [
            'organization' => new OrganizationResource($organization),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        /**
         * Hash::check runs even when the user does not exist would be ideal for
         * constant time; Laravel's own attempt() has the same shape. The real
         * defence against enumeration here is the identical response for both
         * cases, plus the per-email+IP rate limit on this route.
         */
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return $this->invalidCredentials($request);
        }

        if (! $user->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'account_disabled',
                    'message' => 'This account has been disabled. Contact an administrator.',
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->record('auth.logged_in', $user, $user);

        return $this->issueSession($request, $user);
    }

    /**
     * Exchanges the rotating refresh token for a new pair.
     *
     * The token is read from the HttpOnly cookie by default; the body is
     * accepted as a fallback for non-browser clients that have no cookie jar.
     */
    public function refresh(Request $request): JsonResponse
    {
        $cookieName = (string) config('jwt.refresh_cookie.name');
        $plain = $request->cookie($cookieName) ?? $request->input('refresh_token');

        if (! is_string($plain) || $plain === '') {
            return response()->json([
                'error' => [
                    'code' => 'refresh_token_missing',
                    'message' => 'No refresh token was supplied.',
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->tokens->rotateRefreshToken($plain, $request->userAgent(), $request->ip());

        return $this->respondWithTokens(
            $result['user'],
            $result['access'],
            $result['refresh']['plain'],
            [],
            Response::HTTP_OK,
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = $user->memberships()->with('organization')->get();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'organizations' => $memberships->map(fn ($membership): array => [
                    'organization' => new OrganizationResource($membership->organization),
                    'role' => $membership->role->value,
                    'permissions' => array_map(
                        static fn (Permission $permission): string => $permission->value,
                        $membership->role->permissions(),
                    ),
                ]),
            ],
        ]);
    }

    /**
     * Ends this session.
     *
     * Both halves are revoked: the refresh token row is marked revoked, and the
     * access token's `jti` goes on a cache denylist for its remaining lifetime.
     * Without the second step "log out" would be a suggestion for up to fifteen
     * minutes — the standard, and usually unmentioned, weakness of stateless
     * tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $cookieName = (string) config('jwt.refresh_cookie.name');
        $plain = $request->cookie($cookieName) ?? $request->input('refresh_token');

        if (is_string($plain) && $plain !== '') {
            $this->tokens->revokeRefreshToken($plain, 'logout');
        }

        $guard = auth()->guard('api');
        if ($guard instanceof JwtGuard && ($claims = $guard->claims()) !== null) {
            $this->tokens->denyAccessToken($claims);
        }

        $this->audit->record('auth.logged_out', $request->user(), $request->user());

        return response()
            ->json(['data' => ['message' => 'Signed out.']])
            ->withCookie($this->forgetRefreshCookie());
    }

    /** Revokes every session for the current user — "sign out everywhere". */
    public function logoutEverywhere(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $this->tokens->revokeAllForUser($user, 'logout_all');

        $guard = auth()->guard('api');
        if ($guard instanceof JwtGuard && ($claims = $guard->claims()) !== null) {
            $this->tokens->denyAccessToken($claims);
        }

        $this->audit->record('auth.logged_out_everywhere', $user, $user, null, [
            'after' => ['sessions_revoked' => $revoked],
        ]);

        return response()
            ->json(['data' => ['message' => 'All sessions ended.', 'sessions_revoked' => $revoked]])
            ->withCookie($this->forgetRefreshCookie());
    }

    // --------------------------------------------------------------- private

    /** @param array<string, mixed> $extra */
    private function issueSession(Request $request, User $user, array $extra = [], int $status = Response::HTTP_OK): JsonResponse
    {
        $access = $this->tokens->issueAccessToken($user);
        $refresh = $this->tokens->issueRefreshToken($user, null, $request->userAgent(), $request->ip());

        return $this->respondWithTokens($user, $access, $refresh['plain'], $extra, $status);
    }

    /** @param array<string, mixed> $extra */
    private function respondWithTokens(
        User $user,
        IssuedToken $access,
        string $refreshPlain,
        array $extra = [],
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'access_token' => $access->token,
                'token_type' => 'Bearer',
                'expires_in' => $access->expiresIn(),
                'expires_at' => now()->setTimestamp($access->expiresAt)->toIso8601String(),
                ...$extra,
            ],
        ], $status)->withCookie($this->refreshCookie($refreshPlain));
    }

    /**
     * HttpOnly so JavaScript — and therefore any XSS payload — cannot read it.
     * SameSite=Strict so it is never attached to a cross-site request. Path
     * scoped to /api/v1/auth so it is not sent on the hundreds of ordinary API
     * calls that have no use for it, which shrinks its exposure surface to the
     * three endpoints that do.
     */
    private function refreshCookie(string $plain): Cookie
    {
        $config = config('jwt.refresh_cookie');

        return new Cookie(
            name: (string) $config['name'],
            value: $plain,
            expire: now()->addSeconds((int) config('jwt.ttl.refresh')),
            path: (string) $config['path'],
            domain: $config['domain'] ?? null,
            secure: (bool) $config['secure'],
            httpOnly: true,
            raw: false,
            sameSite: (string) $config['same_site'],
        );
    }

    private function forgetRefreshCookie(): Cookie
    {
        $config = config('jwt.refresh_cookie');

        return new Cookie(
            name: (string) $config['name'],
            value: '',
            expire: 1,
            path: (string) $config['path'],
            domain: $config['domain'] ?? null,
            secure: (bool) $config['secure'],
            httpOnly: true,
            raw: false,
            sameSite: (string) $config['same_site'],
        );
    }

    private function invalidCredentials(Request $request): JsonResponse
    {
        // Identical response whether the email is unknown or the password is
        // wrong: anything else turns the login form into a user directory.
        return response()->json([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'Those credentials do not match our records.',
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
