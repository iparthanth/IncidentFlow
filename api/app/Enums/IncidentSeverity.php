<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity drives who gets paged and how hard the clock ticks.
 *
 * Stored as a short string rather than a native database enum so that adding
 * SEV-5 later is a code change, not an `ALTER TYPE` that takes an exclusive
 * lock on a hot table.
 */
enum IncidentSeverity: string
{
    case Sev1 = 'sev1';
    case Sev2 = 'sev2';
    case Sev3 = 'sev3';
    case Sev4 = 'sev4';

    public function label(): string
    {
        return match ($this) {
            self::Sev1 => 'SEV-1 — Critical',
            self::Sev2 => 'SEV-2 — Major',
            self::Sev3 => 'SEV-3 — Minor',
            self::Sev4 => 'SEV-4 — Informational',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sev1 => 'Complete outage or data loss affecting all customers. Page immediately.',
            self::Sev2 => 'Major functionality degraded for many customers. Page during business hours.',
            self::Sev3 => 'Limited impact with a workaround available.',
            self::Sev4 => 'No customer impact; tracked for awareness.',
        };
    }

    /** Ordering weight: lower is more urgent, used for "most severe first" sorts. */
    public function weight(): int
    {
        return match ($this) {
            self::Sev1 => 1,
            self::Sev2 => 2,
            self::Sev3 => 3,
            self::Sev4 => 4,
        };
    }

    /** Target time to acknowledge, in minutes. Surfaced in metrics as an SLA line. */
    public function acknowledgementTargetMinutes(): int
    {
        return match ($this) {
            self::Sev1 => 5,
            self::Sev2 => 15,
            self::Sev3 => 60,
            self::Sev4 => 480,
        };
    }

    /** SEV-1 and SEV-2 wake people up; the rest do not. */
    public function requiresImmediateNotification(): bool
    {
        return $this === self::Sev1 || $this === self::Sev2;
    }

    /** A postmortem is mandatory for the severities that hurt customers. */
    public function requiresPostmortem(): bool
    {
        return $this === self::Sev1 || $this === self::Sev2;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
