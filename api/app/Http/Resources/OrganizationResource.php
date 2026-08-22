<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            // Cast to object so an empty map serialises as {} rather than [].
            // PHP cannot tell the two apart; a typed client can, and rejects the array.
            'settings' => (object) ($this->settings ?? []),
            // The caller's own role, so the frontend can hide controls it
            // would only be told about by a 403 anyway.
            'role' => $this->when(
                $request->user() !== null,
                fn (): ?string => $request->user()?->roleIn($this->resource)?->value,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
