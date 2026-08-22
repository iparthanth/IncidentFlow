<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentEventType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\OrganizationRole;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Postmortem;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Postmortems, and the gate that stops empty ones being published.
 *
 * The interesting behaviour is that publication is blocked by *content*, not
 * only by role. A postmortem with no root cause records that an incident
 * happened and teaches nobody anything — this is the one moment the system can
 * insist the work was actually done.
 */
final class PostmortemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User, Incident} */
    private function resolvedIncident(OrganizationRole $role = OrganizationRole::IncidentCommander): array
    {
        [$organization, $user] = $this->tenantWithMember($role);
        $incidents = app(IncidentService::class);

        $incident = $incidents->create($organization, $user, [
            'title' => 'Checkout outage',
            'severity' => IncidentSeverity::Sev1,
        ]);
        $incident = $incidents->transition($incident, $user, IncidentStatus::Resolved);

        return [$organization, $user, $incident];
    }

    public function test_creating_a_postmortem_appends_a_timeline_event(): void
    {
        [$organization, $user, $incident] = $this->resolvedIncident();

        $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", [
                'summary' => 'A bad deploy took checkout down for 38 minutes.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('postmortems', ['incident_id' => $incident->id]);

        $types = $incident->events()->pluck('type')->all();
        $this->assertContains(IncidentEventType::PostmortemDrafted, $types);
    }

    public function test_upsert_is_idempotent_and_does_not_create_a_second_document(): void
    {
        [$organization, $user, $incident] = $this->resolvedIncident();

        $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", ['summary' => 'First pass.'])
            ->assertCreated();

        $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", ['summary' => 'Second pass, more detail.'])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Second pass, more detail.');

        // One incident, one postmortem — enforced by a unique constraint rather
        // than by hoping the application only ever creates one.
        $this->assertSame(1, Postmortem::query()->count());
    }

    public function test_publishing_is_refused_until_every_required_section_is_filled_in(): void
    {
        [$organization, $user, $incident] = $this->resolvedIncident();

        $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", ['summary' => 'Only a summary so far.'])
            ->assertCreated();

        $response = $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/postmortem/publish");

        $this->assertApiError($response, 422, 'postmortem.incomplete');

        // Naming exactly what is missing turns a blocked publish into a to-do
        // list, rather than a wall the author has to guess their way past.
        $missing = $response->json('error.details.missing_sections');
        $this->assertEqualsCanonicalizing(['root_cause', 'impact', 'resolution'], $missing);

        $this->assertNull(Postmortem::query()->first()?->published_at);
    }

    public function test_a_complete_postmortem_publishes_and_becomes_read_only(): void
    {
        [$organization, $user, $incident] = $this->resolvedIncident();

        $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", [
                'summary' => 'A bad deploy took checkout down for 38 minutes.',
                'root_cause' => 'Release 4.2.1 shipped a migration that locked the orders table.',
                'impact' => 'All checkout traffic failed between 14:02 and 14:40 UTC.',
                'resolution' => 'Rolled back 4.2.1 and rebuilt the index concurrently.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.missing_sections', []);

        $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/postmortem/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_editable', false);

        // Published postmortems are cited by other teams, so a correction has to
        // be an amendment rather than a silent rewrite — refused for everyone,
        // regardless of role.
        $response = $this->actingAsMember($user, $organization)
            ->putJson("/api/v1/incidents/{$incident->id}/postmortem", ['root_cause' => 'Actually it was DNS.']);

        $this->assertApiError($response, 403, 'forbidden');
    }

    public function test_publishing_twice_is_harmless(): void
    {
        [$organization, $user, $incident] = $this->resolvedIncident();

        $this->actingAsMember($user, $organization)->putJson("/api/v1/incidents/{$incident->id}/postmortem", [
            'summary' => 'Summary.',
            'root_cause' => 'Root cause.',
            'impact' => 'Impact.',
            'resolution' => 'Resolution.',
        ])->assertCreated();

        $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/postmortem/publish")
            ->assertOk();

        $publishedAt = Postmortem::query()->first()?->published_at;

        $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/postmortem/publish")
            ->assertOk();

        // The second call is a no-op, not a second publication.
        $this->assertEquals($publishedAt, Postmortem::query()->first()?->published_at);
    }

    public function test_a_responder_cannot_manage_a_postmortem(): void
    {
        [$organization, , $incident] = $this->resolvedIncident();
        $responder = User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        $this->assertApiError(
            $this->actingAsMember($responder, $organization)
                ->putJson("/api/v1/incidents/{$incident->id}/postmortem", ['summary' => 'Sneaking one in.']),
            403,
            'forbidden',
        );
    }

    public function test_postmortems_are_scoped_to_the_calling_tenant(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember(OrganizationRole::IncidentCommander);
        [$organizationB, $userB, $theirIncident] = $this->resolvedIncident();

        $this->actingAsMember($userB, $organizationB)
            ->putJson("/api/v1/incidents/{$theirIncident->id}/postmortem", ['summary' => 'Theirs.'])
            ->assertCreated();

        $response = $this->actingAsMember($userA, $organizationA)
            ->getJson("/api/v1/incidents/{$theirIncident->id}/postmortem");

        $this->assertContains($response->status(), [403, 404]);

        $this->actingAsMember($userA, $organizationA)
            ->getJson('/api/v1/postmortems')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
