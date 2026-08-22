<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The (user, organization) -> role edge. Every authorization decision in the
 * application resolves to a row in this table.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 * @property Carbon|null $joined_at
 */
class OrganizationMember extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<OrganizationMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
