<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncidentEventType;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentEvent>
 */
final class IncidentEventFactory extends Factory
{
    protected $model = IncidentEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'organization_id' => Organization::factory(),
            'type' => IncidentEventType::Created,
            'actor_id' => null,
            'actor_name' => fake()->name(),
            'payload' => [],
            'request_id' => (string) fake()->uuid(),
            'occurred_at' => now(),
        ];
    }
}
