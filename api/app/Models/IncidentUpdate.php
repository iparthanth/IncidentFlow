<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentStatus;
use Database\Factories\IncidentUpdateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A narrative "here is what we know now" post. Distinct from a comment: an
 * update is the record of communication, optionally publishable to customers,
 * and it carries the status the incident was in when it was written.
 *
 * @property int $incident_id
 * @property IncidentStatus|null $status
 * @property IncidentStatus|null $previous_status
 * @property string $message
 * @property bool $is_public
 */
class IncidentUpdate extends Model
{
    /** @use HasFactory<IncidentUpdateFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'user_id',
        'previous_status',
        'status',
        'message',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'previous_status' => IncidentStatus::class,
            'is_public' => 'boolean',
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
}
