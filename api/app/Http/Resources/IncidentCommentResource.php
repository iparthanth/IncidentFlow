<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IncidentComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentComment
 */
final class IncidentCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'edited' => $this->wasEdited(),
            'author' => new UserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Lets the UI show a delete control only where it will succeed.
            'can_delete' => $request->user()?->can('deleteComment', $this->resource) ?? false,
        ];
    }
}
