<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentEventType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\IncidentEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable entry on an incident's timeline.
 *
 * Append-only is enforced at three levels, because one level is never enough:
 *   1. the schema has no `updated_at` and no `deleted_at` to write to;
 *   2. this model throws on update and delete, so a stray `->save()` in future
 *      code fails loudly in tests rather than quietly rewriting history;
 *   3. no route or policy ever exposes a mutating verb for this resource.
 *
 * The reason to care: a timeline is the evidence a postmortem is built from. If
 * it can be edited after the fact, every conclusion drawn from it is unfalsifiable.
 * Corrections are appended as new events, never applied in place.
 *
 * @property int $id
 * @property string $ulid
 * @property int $incident_id
 * @property int $organization_id
 * @property IncidentEventType $type
 * @property array<string, mixed> $payload
 */
class IncidentEvent extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IncidentEventFactory> */
    use HasFactory;
    use HasUlids;

    /** No updated_at column exists; Laravel treats null as "do not track". */
    public const UPDATED_AT = null;

    protected $fillable = [
        'incident_id',
        'organization_id',
        'type',
        'actor_id',
        'actor_name',
        'payload',
        'request_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => IncidentEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** ULIDs live in the `ulid` column; the primary key stays a bigint. */
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
            throw new LogicException('Incident timeline events are append-only and cannot be modified.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Incident timeline events are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Falls back to the snapshotted name when the actor has since been deleted. */
    public function actorLabel(): string
    {
        return $this->actor?->name ?? $this->actor_name ?? 'System';
    }

    public function summary(): string
    {
        return $this->type->describe($this->actorLabel(), $this->payload ?? []);
    }
}
