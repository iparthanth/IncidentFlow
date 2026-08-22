<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\IncidentEvent;
use App\Models\Notification;

/**
 * Side effects that must happen *after* the database transaction commits,
 * collected while inside it.
 *
 * This is the whole discipline in one small class. Publishing to Redis or
 * enqueuing a job from inside a transaction announces a state change that a
 * rollback can still erase — subscribers would see an incident resolved that
 * PostgreSQL never recorded, and a queue worker could start reading a row that
 * does not exist yet. So the transaction only ever *records intent*, and the
 * caller flushes once the commit is real.
 */
final class PendingEffects
{
    /** @var list<IncidentEvent> */
    public array $timelineEvents = [];

    /** @var list<Notification> */
    public array $notifications = [];

    public function addEvent(IncidentEvent $event): IncidentEvent
    {
        $this->timelineEvents[] = $event;

        return $event;
    }

    /** @param list<Notification> $notifications */
    public function addNotifications(array $notifications): void
    {
        $this->notifications = [...$this->notifications, ...$notifications];
    }

    public function isEmpty(): bool
    {
        return $this->timelineEvents === [] && $this->notifications === [];
    }
}
