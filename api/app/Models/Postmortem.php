<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PostmortemStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PostmortemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The post-incident report. One per incident, enforced by a unique constraint
 * on `incident_id` rather than by hoping the application only creates one.
 *
 * @property int $incident_id
 * @property PostmortemStatus $status
 * @property list<array<string, mixed>>|null $action_items
 */
class Postmortem extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PostmortemFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'incident_id',
        'organization_id',
        'author_id',
        'title',
        'summary',
        'root_cause',
        'contributing_factors',
        'impact',
        'resolution',
        'detection_notes',
        'lessons_learned',
        'action_items',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostmortemStatus::class,
            'action_items' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isPublished(): bool
    {
        return $this->status === PostmortemStatus::Published;
    }

    /**
     * A report with no root cause is a report nobody can act on. Publishing is
     * gated on the sections that make it useful, not merely on a role check.
     *
     * @return list<string> the field names still missing
     */
    public function missingRequiredSections(): array
    {
        return array_values(array_filter([
            'summary' => blank($this->summary) ? 'summary' : null,
            'root_cause' => blank($this->root_cause) ? 'root_cause' : null,
            'impact' => blank($this->impact) ? 'impact' : null,
            'resolution' => blank($this->resolution) ? 'resolution' : null,
        ]));
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PostmortemStatus::Published->value);
    }
}
