<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeder has to survive being run twice.
 *
 * docker-compose's `migrate` service runs `migrate --force && db:seed --force`
 * on every `up`, and api/horizon/scheduler wait for that command to *succeed*
 * before they start. So on the second `up` against an existing volume, a
 * seeder that blindly re-inserts fails on a unique constraint and takes the
 * whole stack down with it -- which is exactly what the setup manual tells a
 * reader to do when it says "run docker compose up".
 */
final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_a_second_time_neither_fails_nor_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = [
            'organizations' => Organization::query()->count(),
            'users' => User::query()->count(),
            'incidents' => Incident::query()->count(),
        ];

        $this->assertGreaterThan(0, $before['incidents'], 'The first seed should have produced demo data.');

        // The assertion is that this does not throw. Before the guard it raised
        // SQLSTATE[23505] on organizations_slug_unique.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, [
            'organizations' => Organization::query()->count(),
            'users' => User::query()->count(),
            'incidents' => Incident::query()->count(),
        ], 'A second seed must leave the existing demo data exactly as it was.');
    }
}
