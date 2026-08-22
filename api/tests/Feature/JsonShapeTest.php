<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentSeverity;
use App\Enums\OrganizationRole;
use App\Models\Incident;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Assertions about the raw JSON on the wire, not the decoded PHP array.
 *
 * Every other test in this suite reads `$response->json()`, which decodes to a
 * PHP array — and in PHP an empty map and an empty list are the *same value*.
 * That blind spot is real: three separate fields shipped serialising as `[]`
 * when empty, the whole application was unusable in a browser because
 * `GET /auth/me` failed client-side validation, and 95 passing tests said
 * nothing at all.
 *
 * A typed consumer — zod here, but equally a Swift or Kotlin client — treats
 * `[]` and `{}` as different types and rejects the mismatch. These tests read
 * `getContent()` so the difference is visible.
 */
final class JsonShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_organization_settings_map_serialises_as_an_object(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        $response = $this->actingAsMember($user, $organization)->getJson('/api/v1/organizations');

        $response->assertOk();
        // `settings` is null in the database. PHP would render `?? []` as `[]`.
        $this->assertStringContainsString('"settings":{}', $response->getContent());
        $this->assertStringNotContainsString('"settings":[]', $response->getContent());
    }

    public function test_incident_counts_serialise_as_an_object_whether_or_not_they_were_loaded(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);

        // Creation does not eager-load the counts, so all three are stripped
        // and the array is empty — the exact case that shipped broken.
        $created = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/incidents', [
                'title' => 'Shape check on create',
                'severity' => IncidentSeverity::Sev3->value,
            ]);

        $created->assertCreated();
        $this->assertStringNotContainsString('"counts":[]', $created->getContent());
        $this->assertStringContainsString('"counts":{}', $created->getContent());

        // The list view does load them, so the same field is a populated object.
        $list = $this->actingAsMember($user, $organization)->getJson('/api/v1/incidents');
        $list->assertOk();
        $this->assertStringContainsString('"comments":0', $list->getContent());
    }

    public function test_an_empty_timeline_event_payload_serialises_as_an_object(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        $incident = app(IncidentService::class)
            ->create($organization, $user, ['title' => 'Payload shape', 'severity' => IncidentSeverity::Sev3]);

        // Force the payload empty, which is what a payload-less event looks like.
        $incident->events()->getQuery()->update(['payload' => json_encode([])]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson("/api/v1/incidents/{$incident->id}/events");

        $response->assertOk();
        $this->assertStringNotContainsString('"payload":[]', $response->getContent());
        $this->assertStringContainsString('"payload":{}', $response->getContent());
    }

    /**
     * The mirror image: fields that really are lists must stay arrays. Casting
     * everything to an object would break these just as badly in the other
     * direction.
     */
    public function test_list_fields_stay_arrays(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        Incident::factory()->forOrganization($organization)->create();

        $content = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"data":[', $content);
        $this->assertStringContainsString('"assignees":[', $content);
        $this->assertStringContainsString('"allowed_transitions":[', $content);
    }

    public function test_the_session_payload_the_spa_parses_on_boot_is_well_formed(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // This is the exact call that made the product unusable: a shape error
        // here strands every user on the login screen with no way forward.
        $content = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('"settings":[]', $content);

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded['data']['organizations']);
        $this->assertArrayHasKey('permissions', $decoded['data']['organizations'][0]);
        $this->assertArrayHasKey('organization', $decoded['data']['organizations'][0]);
    }
}
