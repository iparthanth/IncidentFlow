<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded attempt at an idempotent request.
 *
 * The row is inserted *before* the handler runs, in state `in_progress`. That
 * ordering is what makes the mechanism correct under concurrency: two
 * simultaneous requests with the same key race on a unique index, the loser
 * gets a 409 instead of creating a second incident, and neither depends on an
 * application-level "does it exist yet?" check that could interleave.
 *
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    public const string STATUS_IN_PROGRESS = 'in_progress';

    public const string STATUS_COMPLETED = 'completed';

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'organization_id',
        'user_id',
        'key',
        'endpoint',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Canonical hash of the request body, used to detect key reuse with different content. */
    public static function hashPayload(array $payload): string
    {
        // Sorting makes the hash independent of JSON key order, so a client
        // that serialises its object differently on retry still matches.
        $normalised = self::sortRecursive($payload);

        return hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function sortRecursive(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        return $data;
    }

    /** @param Builder<self> $query */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_at', '<', now());
    }
}
