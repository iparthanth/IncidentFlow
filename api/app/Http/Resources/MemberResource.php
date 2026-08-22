<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\OrganizationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationMember
 */
final class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                $this->role->permissions(),
            ),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
