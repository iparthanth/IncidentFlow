<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $service_id
 * @property string $reference
 * @property string $title
 * @property IncidentSeverity $severity
 * @property IncidentStatus $status
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property int|null $time_to_acknowledge_seconds
 * @property int|null $time_to_resolve_seconds
 */
class Incident extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;
    use SoftDeletes;

    /** Columns a client may sort by. Anything else is rejected, not silently ignored. */
    public const array SORTABLE = [
        'created_at',
        'updated_at',
        'severity',
        'status',
        'resolved_at',
        'acknowledged_at',
        'reference',
        'title',
    ];

    protected $fillable = [
        'organization_id',
        'service_id',
        'reference',
        'title',
        'description',
        'impact',
        'severity',
        'status',
        'reported_by',
        'commander_id',
        'detected_at',
        'source',
        'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'mitigated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'time_to_acknowledge_seconds' => 'integer',
            'time_to_resolve_seconds' => 'integer',
        ];
    }

    /**
     * Human-facing reference, built from the organization's own counter so that
     * each tenant sees INC-0001 onwards rather than a shared global sequence
     * that leaks how many incidents every other customer has had.
     */
    public static function referenceFor(int $sequence): string
    {
        return 'INC-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function commander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commander_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'incident_assignees')
            ->withPivot(['role', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /** @return HasMany<IncidentAssignee, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(IncidentAssignee::class);
    }

    /** @return HasMany<IncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->latest('id');
    }

    /** @return HasMany<IncidentComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(IncidentComment::class)->latest('id');
    }

    /** The append-only timeline, oldest first — the order a human reads it in. */
    /** @return HasMany<IncidentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->oldest('id');
    }

    /** @return HasOne<Postmortem, $this> */
    public function postmortem(): HasOne
    {
        return $this->hasOne(Postmortem::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function requiresPostmortem(): bool
    {
        return $this->severity->requiresPostmortem();
    }

    /** Seconds from creation to the given moment, floored at zero. */
    public function elapsedSecondsUntil(Carbon $moment): int
    {
        return max(0, $moment->getTimestamp() - $this->created_at->getTimestamp());
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $statuses
     */
    public function scopeWhereStatusIn(Builder $query, array $statuses): void
    {
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $severities
     */
    public function scopeWhereSeverityIn(Builder $query, array $severities): void
    {
        if ($severities !== []) {
            $query->whereIn('severity', $severities);
        }
    }

    /** @param Builder<self> $query */
    public function scopeAssignedTo(Builder $query, int $userId): void
    {
        $query->where(function (Builder $inner) use ($userId): void {
            $inner->whereHas('assignments', fn (Builder $a) => $a->where('user_id', $userId))
                ->orWhere('commander_id', $userId);
        });
    }

    /**
     * Free-text search across the fields a responder actually types into a
     * search box. `whereLike` with `caseSensitive: false` compiles to ILIKE on
     * PostgreSQL and LIKE on SQLite, so behaviour matches in CI and in prod.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->whereLike('title', $pattern, caseSensitive: false)
                ->orWhereLike('reference', $pattern, caseSensitive: false)
                ->orWhereLike('description', $pattern, caseSensitive: false);
        });
    }

    /** @param Builder<self> $query */
    public function scopeActiveOnly(Builder $query): void
    {
        $query->whereIn('status', IncidentStatus::activeValues());
    }
}
