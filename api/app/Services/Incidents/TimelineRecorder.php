<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Enums\IncidentEventType;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;

/**
 * The single writer to the append-only incident timeline.
 *
 * Funnelling every write through one class is what guarantees the invariant
 * the schema promises: every state change produces exactly one event, carrying
 * the correlation id of the request that caused it, with the actor's name
 * snapshotted so the timeline stays readable after accounts are deleted.
 */
final class TimelineRecorder
{
    public function __construct(private readonly RequestContext $context) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Incident $incident,
        IncidentEventType $type,
        ?User $actor = null,
        array $payload = [],
        ?Carbon $occurredAt = null,
    ): IncidentEvent {
        return IncidentEvent::query()->create([
            'incident_id' => $incident->getKey(),
            'organization_id' => $incident->organization_id,
            'type' => $type,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'payload' => $payload,
            'request_id' => $this->context->requestId(),
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ]);
    }
}
