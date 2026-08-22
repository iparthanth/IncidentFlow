<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Enums\IncidentEventType;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\OrganizationRole;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * The incident state machine, its clocks, and the timeline it writes.
 *
 * These are the assertions that matter most: everything downstream — MTTA,
 * MTTR, SLA attainment, the postmortem gate — is computed from the timestamps
 * written here, so a bug in this file is a bug in every number the product
 * reports.
 */
final class IncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_incident_allocates_a_per_tenant_reference_and_opens_the_timeline(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);
        $service = Service::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/incidents', [
                'title' => 'Checkout returning 500s for all customers',
                'description' => 'Error rate jumped to 100% at 14:02 UTC.',
                'severity' => IncidentSeverity::Sev1->value,
                'service_id' => $service->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.reference', 'INC-0001');
        $response->assertJsonPath('data.status.value', IncidentStatus::Open->value);
        $response->assertJsonPath('data.severity.value', 'sev1');

        $incident = Incident::query()->firstOrFail();

        // Exactly one timeline event, carrying the request's correlation id.
        $this->assertSame(1, $incident->events()->count());
        $event = $incident->events()->firstOrFail();
        $this->assertSame(IncidentEventType::Created, $event->type);
        $this->assertNotNull($event->request_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incident.created',
            'actor_id' => $user->id,
        ]);
    }

    public function test_references_are_sequential_within_a_tenant_and_independent_between_tenants(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember(OrganizationRole::Reporter);
        [$organizationB, $userB] = $this->tenantWithMember(OrganizationRole::Reporter);

        $incidents = app(IncidentService::class);

        $first = $incidents->create($organizationA, $userA, ['title' => 'A one', 'severity' => IncidentSeverity::Sev3]);
        $second = $incidents->create($organizationA, $userA, ['title' => 'A two', 'severity' => IncidentSeverity::Sev3]);
        $other = $incidents->create($organizationB, $userB, ['title' => 'B one', 'severity' => IncidentSeverity::Sev3]);

        $this->assertSame('INC-0001', $first->reference);
        $this->assertSame('INC-0002', $second->reference);
        // Each tenant counts from one; a shared sequence would leak how many
        // incidents every other customer has had.
        $this->assertSame('INC-0001', $other->reference);
    }

    public function test_acknowledging_records_time_to_acknowledge_once_and_only_once(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incidents = app(IncidentService::class);

        Carbon::setTestNow('2026-03-01 10:00:00');
        $incident = $incidents->create($organization, $user, ['title' => 'Latency spike', 'severity' => IncidentSeverity::Sev2]);

        Carbon::setTestNow('2026-03-01 10:04:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Acknowledged);

        $this->assertSame(240, $incident->time_to_acknowledge_seconds);

        // Mitigate, regress, acknowledge again — the first response is still
        // the one that counts. An incident is not acknowledged faster the
        // second time somebody looks at it.
        Carbon::setTestNow('2026-03-01 10:20:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Mitigated);

        Carbon::setTestNow('2026-03-01 10:40:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Acknowledged);

        $this->assertSame(240, $incident->time_to_acknowledge_seconds);

        Carbon::setTestNow();
    }

    public function test_resolving_records_time_to_resolve_and_backfills_a_missing_acknowledgement(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incidents = app(IncidentService::class);

        Carbon::setTestNow('2026-03-01 10:00:00');
        $incident = $incidents->create($organization, $user, ['title' => 'Disk full', 'severity' => IncidentSeverity::Sev3]);

        Carbon::setTestNow('2026-03-01 10:30:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Resolved);

        $this->assertSame(1800, $incident->time_to_resolve_seconds);

        // Someone clearly responded — leaving TTA null would drop this
        // incident out of the MTTA average and flatter the number.
        $this->assertSame(1800, $incident->time_to_acknowledge_seconds);
        $this->assertNotNull($incident->acknowledged_at);

        Carbon::setTestNow();
    }

    public function test_reopening_clears_the_resolution_so_mttr_is_not_flattered(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incidents = app(IncidentService::class);

        Carbon::setTestNow('2026-03-01 10:00:00');
        $incident = $incidents->create($organization, $user, ['title' => 'Cache stampede', 'severity' => IncidentSeverity::Sev2]);

        Carbon::setTestNow('2026-03-01 10:05:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Acknowledged);

        Carbon::setTestNow('2026-03-01 10:30:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Resolved);
        $this->assertSame(1800, $incident->time_to_resolve_seconds);

        Carbon::setTestNow('2026-03-01 11:00:00');
        $incident = $incidents->transition($incident, $user, IncidentStatus::Acknowledged);

        // An incident that came back was never resolved. Keeping the old
        // duration would quietly report a 30-minute recovery for an outage
        // that was still running an hour later.
        $this->assertNull($incident->resolved_at);
        $this->assertNull($incident->time_to_resolve_seconds);
        // The original acknowledgement stands.
        $this->assertSame(300, $incident->time_to_acknowledge_seconds);

        // `events()` is ordered oldest-first (the order a human reads a
        // timeline in), so the newest entry needs an explicit reorder.
        $this->assertSame(
            IncidentEventType::Reopened,
            $incident->events()->reorder('id', 'desc')->firstOrFail()->type,
        );

        Carbon::setTestNow();
    }

    public function test_an_illegal_transition_is_rejected_and_names_the_legal_ones(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incident = app(IncidentService::class)
            ->create($organization, $user, ['title' => 'Queue backlog', 'severity' => IncidentSeverity::Sev3]);

        $response = $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'closed']);

        $this->assertApiError($response, 422, 'incident.illegal_transition');
        $response->assertJsonPath('error.details.allowed', ['acknowledged', 'resolved']);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()?->status);
    }

    public function test_a_closed_incident_refuses_every_further_change(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Administrator);
        $incidents = app(IncidentService::class);

        $incident = $incidents->create($organization, $user, ['title' => 'Old outage', 'severity' => IncidentSeverity::Sev4]);
        $incident = $incidents->transition($incident, $user, IncidentStatus::Resolved);
        $incident = $incidents->transition($incident, $user, IncidentStatus::Closed);

        $response = $this->actingAsMember($user, $organization)
            ->patchJson("/api/v1/incidents/{$incident->id}", ['title' => 'Rewriting history a bit']);

        $this->assertApiError($response, 422, 'incident.terminal_status');
    }

    public function test_a_transition_note_is_recorded_as_an_incident_update(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incident = app(IncidentService::class)
            ->create($organization, $user, ['title' => 'API errors', 'severity' => IncidentSeverity::Sev2]);

        $this->actingAsMember($user, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/status", [
                'status' => 'acknowledged',
                'note' => 'Paging the payments team, rolling back release 4.2.1.',
                'public' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('incident_updates', [
            'incident_id' => $incident->id,
            'previous_status' => 'open',
            'status' => 'acknowledged',
            'is_public' => true,
        ]);
    }

    public function test_the_timeline_is_append_only(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $incident = app(IncidentService::class)
            ->create($organization, $user, ['title' => 'Evidence', 'severity' => IncidentSeverity::Sev3]);

        /** @var IncidentEvent $event */
        $event = $incident->events()->firstOrFail();

        // Enforced by the model as well as by the schema, so a stray ->save()
        // in future code fails a test instead of silently rewriting history.
        try {
            $event->payload = ['tampered' => true];
            $event->save();
            $this->fail('Timeline events must not be updatable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Timeline events must not be deletable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_deleting_an_incident_is_soft_and_keeps_the_evidence(): void
    {
        [$organization, $admin] = $this->tenantWithMember(OrganizationRole::Administrator);
        $incident = app(IncidentService::class)
            ->create($organization, $admin, ['title' => 'Mistaken report', 'severity' => IncidentSeverity::Sev4]);

        $this->actingAsMember($admin, $organization)
            ->deleteJson("/api/v1/incidents/{$incident->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('incidents', ['id' => $incident->id]);
        // The record that it happened, and that it was deleted, both survive.
        $this->assertDatabaseHas('audit_logs', ['action' => 'incident.deleted']);
        $this->assertSame(2, IncidentEvent::query()->where('incident_id', $incident->id)->count());
    }

    public function test_lowering_severity_requires_a_reason_but_raising_it_does_not(): void
    {
        [$organization, $commander] = $this->tenantWithMember(OrganizationRole::IncidentCommander);
        $incident = app(IncidentService::class)
            ->create($organization, $commander, ['title' => 'Elevated errors', 'severity' => IncidentSeverity::Sev2]);

        // Escalation is self-explanatory: something got worse.
        $this->actingAsMember($commander, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/severity", ['severity' => 'sev1'])
            ->assertOk()
            ->assertJsonPath('data.severity.value', 'sev1');

        // Quietly downgrading changes what the postmortem policy requires and
        // how the incident reads in every later report, so it goes on record.
        $response = $this->actingAsMember($commander, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/severity", ['severity' => 'sev4']);

        $this->assertApiError($response, 422, 'validation_failed');

        $this->actingAsMember($commander, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/severity", [
                'severity' => 'sev4',
                'reason' => 'Confirmed limited to one internal dashboard; no customer impact.',
            ])
            ->assertOk();
    }

    public function test_a_viewer_cannot_be_assigned_as_a_responder(): void
    {
        [$organization, $commander] = $this->tenantWithMember(OrganizationRole::IncidentCommander);
        $viewer = User::factory()->memberOf($organization, OrganizationRole::Viewer)->create();

        $incident = app(IncidentService::class)
            ->create($organization, $commander, ['title' => 'Paging test', 'severity' => IncidentSeverity::Sev3]);

        // An assignment that looks like coverage and provides none is worse
        // than no assignment at all.
        $response = $this->actingAsMember($commander, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/assignees", ['user_id' => $viewer->id]);

        $this->assertApiError($response, 422, 'assignee_not_eligible');
    }

    public function test_assigning_a_responder_notifies_only_them(): void
    {
        [$organization, $commander] = $this->tenantWithMember(OrganizationRole::IncidentCommander);
        $responder = User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        $incident = app(IncidentService::class)
            ->create($organization, $commander, ['title' => 'Paging', 'severity' => IncidentSeverity::Sev3]);

        $this->actingAsMember($commander, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/assignees", ['user_id' => $responder->id])
            ->assertCreated();

        $this->assertDatabaseHas('incident_assignees', [
            'incident_id' => $incident->id,
            'user_id' => $responder->id,
        ]);

        // Broadcasting "X was assigned" to the whole room is noise; only the
        // person newly on the hook needs telling.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $responder->id,
            'type' => IncidentEventType::Assigned->value,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $commander->id,
            'type' => IncidentEventType::Assigned->value,
        ]);
    }
}
