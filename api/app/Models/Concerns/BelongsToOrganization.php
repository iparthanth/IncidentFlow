<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant scoping helper.
 *
 * Note what this trait deliberately does *not* do: it does not register a
 * global scope that silently filters by "the current tenant". A global scope
 * makes queries safe by accident and fails open the moment code runs outside a
 * request (queue workers, artisan commands, the scheduler). Instead every
 * query names its organization explicitly via `forOrganization()`, and the
 * `EnsureOrganizationContext` middleware guarantees a resolved organization is
 * always available to name.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param Builder<static> $query */
    public function scopeForOrganization(Builder $query, int|Organization $organization): void
    {
        $query->where(
            $this->qualifyColumn('organization_id'),
            $organization instanceof Organization ? $organization->id : $organization,
        );
    }
}
