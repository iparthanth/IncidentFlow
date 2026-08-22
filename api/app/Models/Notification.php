<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery record, written before the queue job is dispatched.
 *
 * Creating the row first is what makes retries safe: the job's only job is to
 * move *this row* from queued to sent. A retry therefore re-attempts delivery,
 * it never re-applies the incident change that triggered the notification —
 * which is the difference between "the email went out twice" and "the incident
 * was resolved twice".
 *
 * @property string $ulid
 * @property NotificationChannel $channel
 * @property NotificationStatus $status
 * @property int $attempts
 */
class Notification extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'incident_id',
        'channel',
        'type',
        'subject',
        'body',
        'payload',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function markRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        return $this->forceFill([
            'read_at' => now(),
            'status' => NotificationStatus::Read,
        ])->save();
    }

    /** @param Builder<self> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /** @param Builder<self> $query */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
