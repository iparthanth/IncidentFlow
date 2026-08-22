<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every way a credential can be unacceptable, with a machine-readable reason.
 *
 * The reason is deliberately coarse in what it tells the client: an attacker
 * probing tokens learns "invalid", never "the signature was fine but the user
 * is gone". The precise reason is logged, not returned.
 */
final class InvalidTokenException extends RuntimeException
{
    public const string REASON_MALFORMED = 'malformed';

    public const string REASON_EXPIRED = 'expired';

    public const string REASON_SIGNATURE = 'invalid_signature';

    public const string REASON_AUDIENCE = 'wrong_audience';

    public const string REASON_REVOKED = 'revoked';

    public const string REASON_REUSED = 'reused';

    public const string REASON_UNKNOWN_SUBJECT = 'unknown_subject';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $detail = 'Token could not be parsed'): self
    {
        return new self(self::REASON_MALFORMED, $detail);
    }

    public static function expired(): self
    {
        return new self(self::REASON_EXPIRED, 'Token has expired');
    }

    public static function signature(): self
    {
        return new self(self::REASON_SIGNATURE, 'Token signature verification failed');
    }

    public static function audience(string $expected): self
    {
        return new self(self::REASON_AUDIENCE, "Token was not issued for audience [{$expected}]");
    }

    public static function revoked(): self
    {
        return new self(self::REASON_REVOKED, 'Token has been revoked');
    }

    /**
     * Presenting a refresh token that was already rotated means either a replay
     * attack or a stolen token. Either way the whole family is burned.
     */
    public static function reused(): self
    {
        return new self(self::REASON_REUSED, 'Refresh token was already used; all sessions in this family were revoked');
    }

    public static function unknownSubject(): self
    {
        return new self(self::REASON_UNKNOWN_SUBJECT, 'Token subject no longer exists or is inactive');
    }

    /** The message safe to hand back over HTTP. */
    public function publicMessage(): string
    {
        return match ($this->reason) {
            self::REASON_EXPIRED => 'Your session has expired. Please sign in again.',
            self::REASON_REUSED => 'This session was ended for security reasons. Please sign in again.',
            default => 'Authentication credentials are invalid.',
        };
    }
}
