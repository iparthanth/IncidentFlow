<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IncidentUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentUpdate
 */
final class IncidentUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'is_public' => $this->is_public,
            'status' => $this->status?->value,
            'previous_status' => $this->previous_status?->value,
            'author' => new UserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
