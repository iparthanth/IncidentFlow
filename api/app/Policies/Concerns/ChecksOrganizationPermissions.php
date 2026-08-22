<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;

/**
 * Shared plumbing for every policy.
 *
 * Two invariants live here so that no individual policy can forget either one:
 *
 *  - **Permissions, never roles.** Policies ask "may this user transition an
 *    incident?", not "is this user a commander?". Adding a role is then a
 *    one-line change to the role→permission map rather than an audit of every
 *    authorization check in the codebase.
 *
 *  - **Tenant match first.** Every object-level check confirms the resource
 *    belongs to the organization the request is acting within. Without that,
 *    a member of org A holding a valid token could operate on org B's incident
 *    by guessing its id — the classic broken-object-level-authorization bug,
 *    which no amount of role checking catches.
 */
trait ChecksOrganizationPermissions
{
    protected function currentOrganization(): ?Organization
    {
        return app()->bound(Organization::class) ? app(Organization::class) : null;
    }

    /** Permission check against the request's organization. */
    protected function allows(User $user, Permission $permission): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null && $user->hasPermission($permission, $organization);
    }

    /** Permission check that also proves the resource is in the current tenant. */
    protected function allowsWithin(User $user, Permission $permission, ?int $resourceOrganizationId): bool
    {
        $organization = $this->currentOrganization();

        if ($organization === null || $resourceOrganizationId === null) {
            return false;
        }

        if ((int) $organization->getKey() !== $resourceOrganizationId) {
            return false;
        }

        return $user->hasPermission($permission, $organization);
    }
}
