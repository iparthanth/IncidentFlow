<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for rule violations that are the client's fault, not the server's.
 *
 * Carrying the HTTP status and a stable machine-readable code on the exception
 * keeps controllers free of translation logic: the domain says what went wrong,
 * one handler decides how to render it, and the frontend switches on `code`
 * rather than pattern-matching English prose that will be reworded next sprint.
 */
abstract class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
