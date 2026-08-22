<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The two ways an idempotency key can be used wrongly.
 *
 * They are separated because they mean genuinely different things to a client:
 * one says "slow down, your earlier attempt is still running"; the other says
 * "you have a bug — you reused a key with a different body".
 */
final class IdempotencyConflictException extends DomainException
{
    public static function inFlight(string $key): self
    {
        return new self(
            'A request with this Idempotency-Key is still being processed. Retry shortly.',
            'idempotency.in_progress',
            409,
            ['idempotency_key' => $key],
        );
    }

    public static function payloadMismatch(string $key): self
    {
        return new self(
            'This Idempotency-Key was already used with a different request body.',
            'idempotency.payload_mismatch',
            422,
            ['idempotency_key' => $key],
        );
    }
}
