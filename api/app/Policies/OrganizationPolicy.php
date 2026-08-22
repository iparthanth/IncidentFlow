<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationPermissions;

final class OrganizationPolicy
{
    use ChecksOrganizationPermissions;

    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermission(Permission::OrganizationManage, $organization);
    }

    public function viewMembers(User $user): bool
    {
        return $this->allows($user, Permission::MemberView);
    }

    public function manageMembers(User $user): bool
    {
        return $this->allows($user, Permission::MemberManage);
    }

    public function viewAudit(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function viewMetrics(User $user): bool
    {
        return $this->allows($user, Permission::MetricsView);
    }

    /**
     * Privilege-escalation guard.
     *
     * An administrator may grant any role, but nobody may grant a role above
     * their own, and nobody may change their own. Without the second rule a
     * lone administrator could demote themselves and strand the tenant with no
     * one able to manage it; without the first, "manage members" would quietly
     * be equivalent to "become the owner".
     */
    public function assignRole(User $user, OrganizationMember $member, OrganizationRole $target): bool
    {
        $organization = $this->currentOrganization();

        if ($organization === null || $member->organization_id !== (int) $organization->getKey()) {
            return false;
        }

        if (! $user->hasPermission(Permission::MemberManage, $organization)) {
            return false;
        }

        if ($member->user_id === $user->getKey()) {
            return false;
        }

        $actorRole = $user->roleIn($organization);

        return $actorRole !== null
            && $actorRole->outranksOrEquals($target)
            && $actorRole->outranksOrEquals($member->role);
    }

    public function removeMember(User $user, OrganizationMember $member): bool
    {
        return $this->assignRole($user, $member, $member->role);
    }
}
