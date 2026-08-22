<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Incident>
 */
final class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $createdAt = fake()->dateTimeBetween('-60 days', 'now');

        return [
            'organization_id' => Organization::factory(),
            'service_id' => null,
            // Random rather than sequential: factories bypass the service that
            // allocates real references, and a fixed value would collide on the
            // (organization_id, reference) unique index.
            'reference' => 'INC-'.fake()->unique()->numberBetween(1000, 999_999),
            'title' => ucfirst(fake()->words(6, true)),
            'description' => fake()->paragraph(),
            'impact' => fake()->sentence(),
            'severity' => fake()->randomElement(IncidentSeverity::cases()),
            'status' => IncidentStatus::Open,
            'reported_by' => User::factory(),
            'commander_id' => null,
            'source' => 'web',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    public function severity(IncidentSeverity $severity): self
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    /**
     * A realistically acknowledged incident: the clock fields are consistent
     * with each other, so metric assertions built on this state are meaningful
     * rather than testing the factory's imagination.
     */
    public function acknowledged(int $afterSeconds = 300): self
    {
        return $this->state(function (array $attributes) use ($afterSeconds): array {
            $created = Carbon::parse($attributes['created_at'] ?? now());

            return [
                'status' => IncidentStatus::Acknowledged,
                'acknowledged_at' => $created->copy()->addSeconds($afterSeconds),
                'time_to_acknowledge_seconds' => $afterSeconds,
            ];
        });
    }

    public function resolved(int $acknowledgeAfter = 300, int $resolveAfter = 3_600): self
    {
        return $this->state(function (array $attributes) use ($acknowledgeAfter, $resolveAfter): array {
            $created = Carbon::parse($attributes['created_at'] ?? now());

            return [
                'status' => IncidentStatus::Resolved,
                'acknowledged_at' => $created->copy()->addSeconds($acknowledgeAfter),
                'time_to_acknowledge_seconds' => $acknowledgeAfter,
                'resolved_at' => $created->copy()->addSeconds($resolveAfter),
                'time_to_resolve_seconds' => $resolveAfter,
            ];
        });
    }

    public function closed(): self
    {
        return $this->resolved()->state(fn (array $attributes): array => [
            'status' => IncidentStatus::Closed,
            'closed_at' => Carbon::parse($attributes['resolved_at'] ?? now())->addHours(2),
        ]);
    }
}
