<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssigneeRole;
use Database\Factories\IncidentAssigneeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current responder roster for an incident. Historical assignments are not
 * kept here — they are timeline events, which is the one place history lives.
 *
 * @property int $incident_id
 * @property int $user_id
 * @property AssigneeRole $role
 */
class IncidentAssignee extends Model
{
    /** @use HasFactory<IncidentAssigneeFactory> */
    use HasFactory;

    protected $table = 'incident_assignees';

    protected $fillable = [
        'incident_id',
        'user_id',
        'role',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => AssigneeRole::class,
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
