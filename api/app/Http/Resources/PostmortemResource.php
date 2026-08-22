<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Postmortem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Postmortem
 */
final class PostmortemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'title' => $this->title,
            'summary' => $this->summary,
            'root_cause' => $this->root_cause,
            'contributing_factors' => $this->contributing_factors,
            'impact' => $this->impact,
            'resolution' => $this->resolution,
            'detection_notes' => $this->detection_notes,
            'lessons_learned' => $this->lessons_learned,
            'action_items' => $this->action_items ?? [],
            'status' => $this->status->value,
            'is_editable' => $this->status->isEditable(),
            // Publishing is gated on content, not only on role — the client
            // can show exactly which sections still block it.
            'missing_sections' => $this->missingRequiredSections(),
            'published_at' => $this->published_at?->toIso8601String(),
            'author' => new UserResource($this->whenLoaded('author')),
            'incident' => new IncidentResource($this->whenLoaded('incident')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
