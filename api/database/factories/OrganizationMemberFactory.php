<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMember>
 */
final class OrganizationMemberFactory extends Factory
{
    protected $model = OrganizationMember::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Responder,
            'joined_at' => now(),
        ];
    }

    public function role(OrganizationRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function administrator(): self
    {
        return $this->role(OrganizationRole::Administrator);
    }

    public function commander(): self
    {
        return $this->role(OrganizationRole::IncidentCommander);
    }

    public function viewer(): self
    {
        return $this->role(OrganizationRole::Viewer);
    }

    public function reporter(): self
    {
        return $this->role(OrganizationRole::Reporter);
    }
}
