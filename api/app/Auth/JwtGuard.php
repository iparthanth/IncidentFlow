<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\InvalidTokenException;
use App\Models\User;
use App\Services\Auth\TokenClaims;
use App\Services\Auth\TokenService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/**
 * Stateless bearer-token guard.
 *
 * Implemented as a real guard rather than a middleware that calls
 * `auth()->setUser()` so that everything downstream — policies, `$request->user()`,
 * the `auth:api` middleware, Gate checks in queued jobs — works exactly as it
 * does with any first-party Laravel guard. Bolting authentication on beside the
 * framework's own abstraction is how you end up with an endpoint that forgot to
 * run it.
 *
 * The guard resolves identity only. Authorization is a separate question
 * answered by policies against the database, which is why removing someone's
 * role takes effect on their very next request instead of when their token
 * happens to expire.
 */
final class JwtGuard implements Guard
{
    private ?User $user = null;

    private bool $resolved = false;

    /** Why the last resolution failed, so the API can say "expired" vs "invalid". */
    private ?InvalidTokenException $failure = null;

    private ?TokenClaims $claims = null;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly Request $request,
        private readonly TokenService $tokens,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();
        if ($token === null || $token === '') {
            $this->failure = InvalidTokenException::malformed('No bearer token supplied');

            return null;
        }

        try {
            $claims = $this->tokens->decodeAccessToken($token);
        } catch (InvalidTokenException $e) {
            $this->failure = $e;

            return null;
        }

        $user = $this->provider->retrieveById($claims->userId);

        // A token can outlive the account it names. Deactivating a user must
        // lock them out immediately, not in fifteen minutes.
        if (! $user instanceof User || ! $user->is_active) {
            $this->failure = InvalidTokenException::unknownSubject();

            return null;
        }

        $this->claims = $claims;

        return $this->user = $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user instanceof User ? $user : null;
        $this->resolved = true;
        $this->failure = null;

        return $this;
    }

    /** The verified claims of the current request's token, if any. */
    public function claims(): ?TokenClaims
    {
        $this->user();

        return $this->claims;
    }

    public function lastFailure(): ?InvalidTokenException
    {
        $this->user();

        return $this->failure;
    }
}
