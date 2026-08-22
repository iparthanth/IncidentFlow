<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'action' => $this->action,
            'actor' => [
                'id' => $this->actor_id,
                // Falls back to the snapshot when the account is gone, so an
                // audit entry never degrades to "unknown".
                'name' => $this->actor?->name,
                'email' => $this->actor_email,
            ],
            'subject' => [
                'type' => $this->auditable_type,
                'id' => $this->auditable_id,
            ],
            'changes' => $this->changes,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
