<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Email is org-internal directory data, not public, but every
            // member can already see who they work with — hiding it would
            // break assignment pickers without protecting anything.
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            // Only present when the relation was eager-loaded for this context.
            'role' => $this->whenPivotLoaded('organization_members', fn (): ?string => $this->pivot->role),
        ];
    }
}
