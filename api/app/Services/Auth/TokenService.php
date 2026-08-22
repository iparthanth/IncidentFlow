<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OrganizationRole;
use App\Exceptions\InvalidTokenException;
use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Issues and verifies every credential the platform hands out.
 *
 * Three token types, three lifetimes, three threat models:
 *
 *  - access token   15 min, audience `api`, identity only. Not revocable by
 *                   construction, so the TTL *is* the blast radius; a cache
 *                   denylist on `jti` closes the gap for explicit logout.
 *  - refresh token  30 days, opaque random bytes, stored hashed, rotated on
 *                   every use with family-wide revocation on replay.
 *  - realtime ticket 60 s, audience `realtime`, carries org and role because
 *                   the Express service has no database to ask.
 */
final class TokenService
{
    public function __construct(
        private readonly KeyProvider $keys,
        private readonly CacheFactory $cache,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    // ---------------------------------------------------------------- access

    public function issueAccessToken(User $user): IssuedToken
    {
        $now = time();
        $expiresAt = $now + (int) $this->config['ttl']['access'];
        $jti = (string) Str::uuid();

        $claims = [
            'iss' => $this->config['issuer'],
            'aud' => $this->config['audiences']['api'],
            'sub' => (string) $user->getKey(),
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            // Convenience claims for the UI. Never used for authorization —
            // the database is the only authority on what a user may do.
            'name' => $user->name,
            'email' => $user->email,
        ];

        return new IssuedToken($this->encode($claims), $jti, $now, $expiresAt);
    }

    public function decodeAccessToken(string $jwt): TokenClaims
    {
        return $this->decode($jwt, (string) $this->config['audiences']['api']);
    }

    // -------------------------------------------------------------- realtime

    /**
     * A one-minute credential that grants nothing but a read stream.
     *
     * Role and organization are embedded because the realtime tier is
     * stateless by design. The cost is that a role revoked *right now* stays
     * effective on an open stream for at most the ticket TTL — a bounded,
     * documented staleness rather than an unbounded one.
     */
    public function issueRealtimeTicket(User $user, Organization $organization, OrganizationRole $role): IssuedToken
    {
        $now = time();
        $expiresAt = $now + (int) $this->config['ttl']['realtime'];
        $jti = (string) Str::uuid();

        $claims = [
            'iss' => $this->config['issuer'],
            'aud' => $this->config['audiences']['realtime'],
            'sub' => (string) $user->getKey(),
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'org_id' => (int) $organization->getKey(),
            'role' => $role->value,
            'name' => $user->name,
        ];

        return new IssuedToken($this->encode($claims), $jti, $now, $expiresAt);
    }

    // --------------------------------------------------------------- refresh

    /**
     * @return array{plain: string, model: RefreshToken}
     */
    public function issueRefreshToken(
        User $user,
        ?RefreshToken $parent = null,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): array {
        // 256 bits of CSPRNG output. Opaque, not a JWT: there is nothing a
        // client needs to read inside a refresh token, and an opaque value
        // cannot leak claims if it ends up somewhere it should not.
        $plain = bin2hex(random_bytes(32));

        $model = RefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => RefreshToken::hash($plain),
            'family_id' => $parent?->family_id ?? (string) Str::uuid(),
            'parent_id' => $parent?->getKey(),
            'expires_at' => now()->addSeconds((int) $this->config['ttl']['refresh']),
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
            'ip_address' => $ipAddress,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /**
     * Exchanges a refresh token for a new pair, invalidating the old one.
     *
     * The whole operation is one transaction with a row lock, so two tabs
     * refreshing simultaneously cannot both succeed and leave two live
     * descendants of the same token.
     *
     * @return array{user: User, access: IssuedToken, refresh: array{plain: string, model: RefreshToken}}
     */
    public function rotateRefreshToken(string $plain, ?string $userAgent = null, ?string $ipAddress = null): array
    {
        $hash = RefreshToken::hash($plain);

        /** @var RefreshToken|null $presented */
        $presented = RefreshToken::query()->where('token_hash', $hash)->first();

        if ($presented === null) {
            throw InvalidTokenException::revoked();
        }

        /**
         * Reuse detection, handled *before* the rotation transaction opens.
         *
         * A rotated token presented a second time means someone kept a copy.
         * We cannot tell the thief from the victim, so the whole family is
         * revoked. Crucially this cannot live inside the transaction below:
         * that transaction ends by throwing, which would roll the revocation
         * straight back and leave the stolen family live — the security
         * control would look present in the code and do nothing at runtime.
         */
        if ($presented->revoked_at !== null) {
            DB::transaction(static function () use ($presented): void {
                RefreshToken::query()
                    ->family($presented->family_id)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'revoked_reason' => 'family_reuse_detected',
                        'updated_at' => now(),
                    ]);
            });

            throw InvalidTokenException::reused();
        }

        return DB::transaction(function () use ($hash, $userAgent, $ipAddress): array {
            /** @var RefreshToken|null $token */
            $token = RefreshToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                throw InvalidTokenException::revoked();
            }

            // Re-checked under the lock: two tabs refreshing at once must not
            // both rotate the same token and leave two live descendants.
            if ($token->revoked_at !== null) {
                throw InvalidTokenException::reused();
            }

            if ($token->expires_at->isPast()) {
                $token->revoke('expired');

                throw InvalidTokenException::expired();
            }

            /** @var User|null $user */
            $user = User::query()->find($token->user_id);
            if ($user === null || ! $user->is_active) {
                $token->revoke('subject_inactive');

                throw InvalidTokenException::unknownSubject();
            }

            $token->revoke('rotated');

            return [
                'user' => $user,
                'access' => $this->issueAccessToken($user),
                'refresh' => $this->issueRefreshToken($user, $token, $userAgent, $ipAddress),
            ];
        });
    }

    /** Ends one session. Silent when the token is already gone — logout is idempotent. */
    public function revokeRefreshToken(string $plain, string $reason = 'logout'): void
    {
        RefreshToken::query()
            ->where('token_hash', RefreshToken::hash($plain))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    /** Ends every session for a user — used on password change and by admins. */
    public function revokeAllForUser(User $user, string $reason = 'revoked_all'): int
    {
        return RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    // -------------------------------------------------------------- denylist

    /**
     * Marks an access token unusable for the remainder of its life.
     *
     * The cache entry expires exactly when the token would have anyway, so the
     * denylist can never grow without bound — the classic objection to
     * denylisting stateless tokens does not apply.
     */
    public function denyAccessToken(TokenClaims $claims): void
    {
        if (! ($this->config['denylist']['enabled'] ?? false)) {
            return;
        }

        $ttl = $claims->secondsUntilExpiry();
        if ($ttl <= 0) {
            return;
        }

        $this->denylistStore()->put($this->denylistKey($claims->jti), true, $ttl);
    }

    public function isDenied(string $jti): bool
    {
        if (! ($this->config['denylist']['enabled'] ?? false)) {
            return false;
        }

        return (bool) $this->denylistStore()->get($this->denylistKey($jti), false);
    }

    // --------------------------------------------------------------- private

    /** @param array<string, mixed> $claims */
    private function encode(array $claims): string
    {
        return JWT::encode($claims, $this->privateKeyResource(), (string) $this->config['algorithm']);
    }

    private function decode(string $jwt, string $expectedAudience): TokenClaims
    {
        JWT::$leeway = (int) $this->config['leeway'];

        try {
            $decoded = JWT::decode($jwt, new Key($this->keys->publicKey(), (string) $this->config['algorithm']));
        } catch (ExpiredException) {
            throw InvalidTokenException::expired();
        } catch (SignatureInvalidException) {
            throw InvalidTokenException::signature();
        } catch (BeforeValidException) {
            throw InvalidTokenException::malformed('Token is not valid yet');
        } catch (UnexpectedValueException $e) {
            throw InvalidTokenException::malformed($e->getMessage());
        }

        $claims = (array) $decoded;

        // firebase/php-jwt validates `exp`/`nbf` but leaves issuer and audience
        // to the caller. Skipping these is how a ticket gets replayed as an
        // access token, so both are checked explicitly.
        if (($claims['iss'] ?? null) !== $this->config['issuer']) {
            throw InvalidTokenException::malformed('Token issuer mismatch');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($expectedAudience, $audiences, strict: true)) {
            throw InvalidTokenException::audience($expectedAudience);
        }

        $subject = $claims['sub'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (! is_string($subject) || ! ctype_digit($subject) || ! is_string($jti)) {
            throw InvalidTokenException::malformed('Token is missing required claims');
        }

        if ($this->isDenied($jti)) {
            throw InvalidTokenException::revoked();
        }

        return new TokenClaims(
            userId: (int) $subject,
            jti: $jti,
            issuedAt: (int) ($claims['iat'] ?? 0),
            expiresAt: (int) ($claims['exp'] ?? 0),
            audience: $expectedAudience,
        );
    }

    /** @return \OpenSSLAsymmetricKey|string */
    private function privateKeyResource(): mixed
    {
        $passphrase = $this->keys->passphrase();
        if ($passphrase === null) {
            return $this->keys->privateKey();
        }

        $resource = openssl_pkey_get_private($this->keys->privateKey(), $passphrase);
        if ($resource === false) {
            throw new \RuntimeException('Unable to unlock the JWT private key — check JWT_PASSPHRASE.');
        }

        return $resource;
    }

    private function denylistStore(): Repository
    {
        return $this->cache->store($this->config['denylist']['cache_store'] ?? null);
    }

    private function denylistKey(string $jti): string
    {
        return ((string) ($this->config['denylist']['prefix'] ?? 'jwt:denylist:')).$jti;
    }
}
