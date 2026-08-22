<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A thing that can break: an API, a queue, a payment provider integration.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property int $tier
 * @property bool $is_active
 */
class Service extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'owner_team',
        'tier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $service): void {
            $service->slug = $service->slug ?: static::uniqueSlugFor($service->organization_id, $service->name);
        });
    }

    /** Slugs are unique per organization, so two tenants may both own "checkout". */
    public static function uniqueSlugFor(int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'service';
        $slug = $base;
        $suffix = 1;

        while (static::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
