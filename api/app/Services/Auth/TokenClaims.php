<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * The verified contents of an access token.
 *
 * Note what is *absent*: no role, no organization. Authorization is resolved
 * from the database on every request, so revoking someone's role takes effect
 * immediately rather than whenever their token happens to expire. The token
 * answers "who is this?"; the database answers "what may they do?".
 */
final readonly class TokenClaims
{
    public function __construct(
        public int $userId,
        public string $jti,
        public int $issuedAt,
        public int $expiresAt,
        public string $audience,
    ) {}

    public function secondsUntilExpiry(): int
    {
        return max(0, $this->expiresAt - time());
    }
}
