<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Permanent, append-only record of who changed what.
 *
 * Distinct from the incident timeline: the timeline is a product feature that
 * responders read, this is a compliance artifact that covers *every* model
 * mutation including member role changes and service edits. Same immutability
 * guarantees, different audience.
 *
 * @property string $ulid
 * @property string $action
 * @property array{before?: array<string, mixed>, after?: array<string, mixed>}|null $changes
 */
class AuditLog extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'actor_id',
        'actor_email',
        'action',
        'auditable_type',
        'auditable_id',
        'changes',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
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

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit log entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param Builder<self> $query */
    public function scopeAction(Builder $query, string $action): void
    {
        $query->where('action', $action);
    }
}
