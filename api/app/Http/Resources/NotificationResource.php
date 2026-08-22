<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'type' => $this->type,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'subject' => $this->subject,
            'body' => $this->body,
            // Cast to object so an empty map serialises as {} rather than [].
            // PHP cannot tell the two apart; a typed client can, and rejects the array.
            'payload' => (object) ($this->payload ?? []),
            'incident_id' => $this->incident_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
