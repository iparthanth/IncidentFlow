<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentUpdate>
 */
final class IncidentUpdateFactory extends Factory
{
    protected $model = IncidentUpdate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'status' => IncidentStatus::Acknowledged,
            'previous_status' => IncidentStatus::Open,
            'message' => fake()->sentence(12),
            'is_public' => false,
        ];
    }

    public function public(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
