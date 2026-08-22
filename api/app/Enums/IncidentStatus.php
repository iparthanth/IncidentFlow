<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The incident lifecycle, expressed as an explicit state machine.
 *
 * Keeping the legal transitions in one place — rather than scattering `if`
 * statements across controllers — is what makes "you cannot close an incident
 * that was never resolved" a property of the domain instead of a bug waiting
 * for an inattentive endpoint.
 *
 *   open ─────────► acknowledged ─────► mitigated ─────► resolved ─────► closed
 *     └──────────────────────────────────────────────────┘   ▲
 *                     (small incidents resolve directly)     │
 *                                     reopen ────────────────┘
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Mitigated => 'Mitigated',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // A trivial incident can go straight to resolved without ceremony.
            self::Open => [self::Acknowledged, self::Resolved],
            self::Acknowledged => [self::Mitigated, self::Resolved],
            // Mitigation that does not hold sends the incident back to active work.
            self::Mitigated => [self::Resolved, self::Acknowledged],
            // Resolved is reversible: an incident that recurs was never resolved.
            self::Resolved => [self::Closed, self::Acknowledged],
            // Closed is terminal. Post-closure findings belong in the postmortem.
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** Still consuming responder attention. */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Acknowledged, self::Mitigated], strict: true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * A transition backwards from resolved invalidates a previously recorded
     * resolution time — the incident demonstrably was not resolved.
     */
    public function isReopeningFrom(self $previous): bool
    {
        return $previous === self::Resolved && $this === self::Acknowledged;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isActive()),
        ));
    }
}
