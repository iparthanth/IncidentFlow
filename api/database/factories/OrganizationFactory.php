<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            // Explicit rather than relying on the model's creating hook, so a
            // factory-built organization is unique even inside a single test
            // where two companies could otherwise slugify identically.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'timezone' => 'UTC',
            'incident_sequence' => 0,
            'settings' => null,
        ];
    }
}
