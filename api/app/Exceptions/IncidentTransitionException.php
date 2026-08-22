<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\IncidentStatus;

/**
 * The state machine refused a move.
 *
 * The response names the transitions that *are* available, so a client that
 * hits this can render a correct UI rather than guessing — and so an engineer
 * reading the log knows the incident's actual state without another query.
 */
final class IncidentTransitionException extends DomainException
{
    public static function illegal(IncidentStatus $from, IncidentStatus $to): self
    {
        return new self(
            sprintf('An incident cannot move from %s to %s.', $from->label(), $to->label()),
            'incident.illegal_transition',
            422,
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(
                    static fn (IncidentStatus $status): string => $status->value,
                    $from->allowedTransitions(),
                ),
            ],
        );
    }

    public static function alreadyInStatus(IncidentStatus $status): self
    {
        return new self(
            sprintf('The incident is already %s.', $status->label()),
            'incident.status_unchanged',
            422,
            ['status' => $status->value],
        );
    }

    public static function terminal(IncidentStatus $status): self
    {
        return new self(
            sprintf('%s incidents are closed to further changes.', $status->label()),
            'incident.terminal_status',
            422,
            ['status' => $status->value],
        );
    }
}
