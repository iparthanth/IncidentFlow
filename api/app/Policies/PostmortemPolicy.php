<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Postmortem;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationPermissions;

final class PostmortemPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PostmortemView);
    }

    public function view(User $user, Postmortem $postmortem): bool
    {
        return $this->allowsWithin($user, Permission::PostmortemView, $postmortem->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PostmortemManage);
    }

    /**
     * A published postmortem is a document other teams cite. Editing it in
     * place would silently rewrite what they read, so the policy refuses
     * regardless of role — authority does not make an amendment invisible.
     */
    public function update(User $user, Postmortem $postmortem): bool
    {
        if (! $postmortem->status->isEditable()) {
            return false;
        }

        return $this->allowsWithin($user, Permission::PostmortemManage, $postmortem->organization_id);
    }

    public function publish(User $user, Postmortem $postmortem): bool
    {
        return $this->allowsWithin($user, Permission::PostmortemPublish, $postmortem->organization_id);
    }

    public function delete(User $user, Postmortem $postmortem): bool
    {
        return $this->allowsWithin($user, Permission::PostmortemManage, $postmortem->organization_id)
            && ! $postmortem->isPublished();
    }
}
