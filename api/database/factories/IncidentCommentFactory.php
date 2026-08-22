<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentComment>
 */
final class IncidentCommentFactory extends Factory
{
    protected $model = IncidentComment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
