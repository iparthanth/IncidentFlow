<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property string $timezone
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'timezone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Cached per-request so a policy check inside a loop does not re-query the
     * membership table once per incident in a paginated list.
     *
     * @var array<int, OrganizationMember|null>
     */
    private array $membershipCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<OrganizationMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Incident, $this> */
    public function reportedIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'reported_by');
    }

    /** @return HasMany<Incident, $this> */
    public function commandedIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'commander_id');
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function membershipIn(int|Organization $organization): ?OrganizationMember
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->membershipCache[$organizationId] ??= $this->relationLoaded('memberships')
            ? $this->memberships->firstWhere('organization_id', $organizationId)
            : $this->memberships()->where('organization_id', $organizationId)->first();
    }

    public function roleIn(int|Organization $organization): ?OrganizationRole
    {
        return $this->membershipIn($organization)?->role;
    }

    public function belongsToOrganization(int|Organization $organization): bool
    {
        return $this->membershipIn($organization) !== null;
    }

    /**
     * The single authorization question the whole application asks.
     * Inactive accounts are refused everything without needing a separate
     * check at each call site.
     */
    public function hasPermission(Permission $permission, int|Organization $organization): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->roleIn($organization)?->has($permission) ?? false;
    }

    /** Clears the request-scoped membership cache after a role change. */
    public function forgetMembershipCache(): void
    {
        $this->membershipCache = [];
        $this->unsetRelation('memberships');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeInOrganization(Builder $query, int $organizationId): void
    {
        $query->whereHas('memberships', fn (Builder $inner) => $inner->where('organization_id', $organizationId));
    }
}
