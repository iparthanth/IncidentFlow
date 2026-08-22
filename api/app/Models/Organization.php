<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property array<string, mixed>|null $settings
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'incident_sequence' => 'integer',
        ];
    }

    /**
     * Reserves the next incident number for this tenant.
     *
     * Must be called inside a transaction: the row lock is what serialises
     * concurrent reporters, and it is released on commit. Outside a
     * transaction the lock would be pointless and two callers could collide.
     */
    public function allocateIncidentNumber(): int
    {
        /** @var self $locked */
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        $next = $locked->incident_sequence + 1;

        $locked->forceFill(['incident_sequence' => $next])->save();
        $this->setAttribute('incident_sequence', $next);

        return $next;
    }

    protected static function booted(): void
    {
        static::creating(function (self $organization): void {
            $organization->slug = $organization->slug ?: static::uniqueSlug($organization->name);
        });
    }

    /** Slug collisions are resolved by suffixing, never by failing the request. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $suffix = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<OrganizationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Severity SLA targets, per-tenant overridable.
     *
     * @return array<string, int>
     */
    public function acknowledgementTargets(): array
    {
        $configured = $this->settings['acknowledgement_targets'] ?? [];

        return array_map(
            static fn (mixed $minutes): int => (int) $minutes,
            is_array($configured) ? $configured : [],
        );
    }

    public function administrators(): HasMany
    {
        return $this->members()->where('role', OrganizationRole::Administrator->value);
    }
}
