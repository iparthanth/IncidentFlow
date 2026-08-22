<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hashed once per process, not once per user.
     *
     * bcrypt is intentionally slow — that is its job — and a suite that builds
     * a few hundred users would otherwise spend most of its runtime hashing
     * the same string over and over.
     */
    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'timezone' => 'UTC',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): self
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Creates the membership alongside the user.
     *
     * Almost every test needs "a responder in this organization" rather than a
     * bare user — without a membership the user can do literally nothing, so
     * this keeps the arrange step of each test to one line.
     */
    public function memberOf(Organization $organization, OrganizationRole $role = OrganizationRole::Responder): self
    {
        return $this->afterCreating(function (User $user) use ($organization, $role): void {
            OrganizationMember::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role,
                'joined_at' => now(),
            ]);

            $user->forgetMembershipCache();
        });
    }
}
