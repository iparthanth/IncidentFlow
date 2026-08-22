<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IncidentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A timeline entry.
 *
 * `id` is the ULID, not the primary key — the same identifier the realtime
 * stream uses as its SSE event id. That is what lets the client deduplicate a
 * live event against one it already fetched over HTTP, and what makes
 * `Last-Event-ID` resume meaningful after a reconnect.
 *
 * @mixin IncidentEvent
 */
final class IncidentEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'summary' => $this->summary(),
            'incident_id' => $this->incident_id,
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actorLabel(),
            ],
            // Cast to object so an empty map serialises as {} rather than [].
            // PHP cannot tell the two apart; a typed client can, and rejects the array.
            'payload' => (object) ($this->payload ?? []),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'request_id' => $this->request_id,
        ];
    }
}
