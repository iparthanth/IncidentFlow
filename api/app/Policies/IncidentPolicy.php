<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationPermissions;

final class IncidentPolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::IncidentView);
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentView, $incident->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::IncidentCreate);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentUpdate, $incident->organization_id);
    }

    public function transition(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentTransition, $incident->organization_id);
    }

    public function assign(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentAssign, $incident->organization_id);
    }

    /** Severity changes and handing over command — commander-and-above levers. */
    public function command(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentCommand, $incident->organization_id);
    }

    public function comment(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::CommentCreate, $incident->organization_id);
    }

    public function postUpdate(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::UpdateCreate, $incident->organization_id);
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $this->allowsWithin($user, Permission::IncidentDelete, $incident->organization_id);
    }

    public function restore(User $user, Incident $incident): bool
    {
        return $this->delete($user, $incident);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Permission::ExportRun);
    }

    /**
     * Authors may retract their own comment; moderating anyone else's needs the
     * explicit permission. Ownership is a grant, not a bypass — the author must
     * still be a member of the tenant the comment lives in.
     */
    public function deleteComment(User $user, IncidentComment $comment): bool
    {
        $organizationId = $comment->incident?->organization_id;

        if ($comment->user_id === $user->getKey()) {
            return $this->allowsWithin($user, Permission::CommentCreate, $organizationId);
        }

        return $this->allowsWithin($user, Permission::CommentModerate, $organizationId);
    }
}
