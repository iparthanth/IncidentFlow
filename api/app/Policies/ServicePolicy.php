<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationPermissions;

final class ServicePolicy
{
    use ChecksOrganizationPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ServiceView);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->allowsWithin($user, Permission::ServiceView, $service->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ServiceManage);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->allowsWithin($user, Permission::ServiceManage, $service->organization_id);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
