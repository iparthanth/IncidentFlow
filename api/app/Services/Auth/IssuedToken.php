<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * A freshly minted JWT plus the metadata a client needs to schedule its own
 * refresh, so the frontend never has to decode the token to find out when it
 * dies.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public string $jti,
        public int $issuedAt,
        public int $expiresAt,
    ) {}

    public function expiresIn(): int
    {
        return max(0, $this->expiresAt - $this->issuedAt);
    }
}
