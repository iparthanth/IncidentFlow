<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * The full incident representation.
 *
 * Enums are serialised as `{value, label}` pairs rather than bare strings:
 * the value is what the client switches on and sends back, the label is what
 * it renders. Shipping only the value forces every consumer to reimplement the
 * same mapping and drift from it — a status page saying "sev1" instead of
 * "SEV-1 — Critical" is a small thing that reads as unfinished.
 *
 * @mixin Incident
 */
final class IncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'impact' => $this->impact,

            'severity' => [
                'value' => $this->severity->value,
                'label' => $this->severity->label(),
                'weight' => $this->severity->weight(),
                'requires_postmortem' => $this->severity->requiresPostmortem(),
                'acknowledgement_target_minutes' => $this->severity->acknowledgementTargetMinutes(),
            ],

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'is_active' => $this->status->isActive(),
                // The client renders exactly the buttons the state machine
                // will accept, instead of showing all five and discovering
                // the rules through 422s.
                'allowed_transitions' => array_map(
                    static fn ($status): string => $status->value,
                    $this->status->allowedTransitions(),
                ),
            ],

            'source' => $this->source,
            'external_reference' => $this->external_reference,

            'timestamps' => [
                'detected_at' => $this->detected_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
                'mitigated_at' => $this->mitigated_at?->toIso8601String(),
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],

            'durations' => [
                'time_to_acknowledge_seconds' => $this->time_to_acknowledge_seconds,
                'time_to_resolve_seconds' => $this->time_to_resolve_seconds,
                // Live counter for anything still open, so the list can show
                // "running for 42m" without the client knowing server time.
                'open_for_seconds' => $this->status->isActive() && $this->created_at !== null
                    ? max(0, now()->getTimestamp() - $this->created_at->getTimestamp())
                    : null,
            ],

            'service' => new ServiceResource($this->whenLoaded('service')),
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'commander' => new UserResource($this->whenLoaded('commander')),
            'assignees' => UserResource::collection($this->whenLoaded('assignees')),
            'postmortem' => new PostmortemResource($this->whenLoaded('postmortem')),
            'events' => IncidentEventResource::collection($this->whenLoaded('events')),
            'updates' => IncidentUpdateResource::collection($this->whenLoaded('updates')),
            'comments' => IncidentCommentResource::collection($this->whenLoaded('comments')),

            // Cast to object: when none of the counts were loaded, Laravel
            // strips all three and PHP serialises the empty array as [] rather
            // than {}. A typed client rejects the array, and the failure looks
            // nothing like its cause.
            'counts' => (object) array_filter([
                'comments' => $this->whenCounted('comments'),
                'updates' => $this->whenCounted('updates'),
                'events' => $this->whenCounted('events'),
            ], static fn (mixed $value): bool => ! $value instanceof MissingValue),
        ];
    }
}
