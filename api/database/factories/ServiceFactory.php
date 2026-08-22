<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
final class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'owner_team' => fake()->randomElement(['Platform', 'Payments', 'Growth', 'Data', 'Mobile']),
            'tier' => fake()->numberBetween(1, 3),
            'is_active' => true,
        ];
    }

    public function tier(int $tier): self
    {
        return $this->state(fn (): array => ['tier' => $tier]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
