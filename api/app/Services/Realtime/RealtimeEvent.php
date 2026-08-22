<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Enums\IncidentEventType;
use App\Models\IncidentEvent;
use Illuminate\Support\Carbon;

/**
 * The published envelope — the wire contract with the Node realtime service.
 *
 * Its counterpart is `RealtimeEventSchema` in realtime/src/types.ts. The two
 * must be changed together, which is why `VERSION` exists: a consumer running
 * older code drops envelopes it does not understand instead of crashing, so the
 * two services can be deployed independently in either order.
 */
final readonly class RealtimeEvent
{
    public const int VERSION = 1;

    public function __construct(
        public string $id,
        public string $type,
        public int $organizationId,
        public ?int $incidentId,
        public Carbon $occurredAt,
        /** @var array{id: int|null, name: string|null}|null */
        public ?array $actor,
        public ?string $requestId,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}

    /**
     * Derives the envelope from a persisted timeline event, so what the browser
     * receives live and what it reads back from the API are the same event with
     * the same identity — the client can therefore deduplicate by `id` after a
     * reconnect without any special casing.
     */
    public static function fromTimelineEvent(IncidentEvent $event, array $extraPayload = []): self
    {
        return new self(
            id: $event->ulid,
            type: $event->type instanceof IncidentEventType ? $event->type->value : (string) $event->type,
            organizationId: $event->organization_id,
            incidentId: $event->incident_id,
            occurredAt: $event->occurred_at ?? Carbon::now(),
            actor: [
                'id' => $event->actor_id,
                'name' => $event->actorLabel(),
            ],
            requestId: $event->request_id,
            payload: array_merge($event->payload ?? [], $extraPayload),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'id' => $this->id,
            'type' => $this->type,
            'organization_id' => $this->organizationId,
            'incident_id' => $this->incidentId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'actor' => $this->actor,
            'request_id' => $this->requestId,
            'payload' => (object) $this->payload,
        ];
    }

    /** Events fan out on one channel per organization; see hub.ts for why. */
    public function channel(string $prefix): string
    {
        return sprintf('%s:org:%d', $prefix, $this->organizationId);
    }
}
