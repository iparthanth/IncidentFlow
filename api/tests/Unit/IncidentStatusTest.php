<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentStatus;
use PHPUnit\Framework\TestCase;

/**
 * The state machine, tested exhaustively.
 *
 * This is the one place in the codebase where "test every combination" is
 * cheap and worth it: five states, twenty-five ordered pairs, and a wrong
 * answer on any of them means an incident can reach a state its timestamps
 * and metrics were never designed for.
 */
final class IncidentStatusTest extends TestCase
{
    public function test_open_may_only_be_acknowledged_or_resolved(): void
    {
        $this->assertSame(
            [IncidentStatus::Acknowledged, IncidentStatus::Resolved],
            IncidentStatus::Open->allowedTransitions(),
        );
    }

    public function test_closed_is_terminal(): void
    {
        $this->assertSame([], IncidentStatus::Closed->allowedTransitions());
        $this->assertTrue(IncidentStatus::Closed->isTerminal());

        foreach (IncidentStatus::cases() as $target) {
            $this->assertFalse(
                IncidentStatus::Closed->canTransitionTo($target),
                "Closed should not be able to move to {$target->value}",
            );
        }
    }

    public function test_an_incident_cannot_be_closed_without_being_resolved_first(): void
    {
        foreach ([IncidentStatus::Open, IncidentStatus::Acknowledged, IncidentStatus::Mitigated] as $from) {
            $this->assertFalse(
                $from->canTransitionTo(IncidentStatus::Closed),
                "{$from->value} should not close directly",
            );
        }

        $this->assertTrue(IncidentStatus::Resolved->canTransitionTo(IncidentStatus::Closed));
    }

    public function test_resolved_can_be_reopened_but_open_cannot_be_reached_again(): void
    {
        $this->assertTrue(IncidentStatus::Resolved->canTransitionTo(IncidentStatus::Acknowledged));

        // Nothing returns to Open: the incident has demonstrably been seen by
        // a human, so re-entering the "nobody has looked at this" state would
        // corrupt time-to-acknowledge.
        foreach (IncidentStatus::cases() as $from) {
            $this->assertFalse(
                $from->canTransitionTo(IncidentStatus::Open),
                "{$from->value} should not be able to return to Open",
            );
        }
    }

    public function test_reopening_is_recognised_only_from_resolved(): void
    {
        $this->assertTrue(IncidentStatus::Acknowledged->isReopeningFrom(IncidentStatus::Resolved));
        $this->assertFalse(IncidentStatus::Acknowledged->isReopeningFrom(IncidentStatus::Open));
        $this->assertFalse(IncidentStatus::Mitigated->isReopeningFrom(IncidentStatus::Resolved));
    }

    public function test_mitigation_can_be_walked_back(): void
    {
        // Mitigation that does not hold must be able to return to active work,
        // otherwise the only way to record a regression is to lie.
        $this->assertTrue(IncidentStatus::Mitigated->canTransitionTo(IncidentStatus::Acknowledged));
    }

    public function test_active_statuses_are_exactly_the_pre_resolution_ones(): void
    {
        $this->assertSame(['open', 'acknowledged', 'mitigated'], IncidentStatus::activeValues());

        $this->assertTrue(IncidentStatus::Open->isActive());
        $this->assertTrue(IncidentStatus::Acknowledged->isActive());
        $this->assertTrue(IncidentStatus::Mitigated->isActive());
        $this->assertFalse(IncidentStatus::Resolved->isActive());
        $this->assertFalse(IncidentStatus::Closed->isActive());
    }

    public function test_no_status_may_transition_to_itself(): void
    {
        foreach (IncidentStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} should not list itself as a transition",
            );
        }
    }
}
