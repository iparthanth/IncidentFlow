<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IncidentCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Internal discussion. Soft-deleted rather than removed so that a comment
 * referenced from the timeline does not become a dangling pointer.
 *
 * @property int $incident_id
 * @property string $body
 */
class IncidentComment extends Model
{
    /** @use HasFactory<IncidentCommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'incident_id',
        'user_id',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
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
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }
}
