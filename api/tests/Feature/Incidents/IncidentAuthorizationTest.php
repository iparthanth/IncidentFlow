<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Enums\IncidentSeverity;
use App\Enums\OrganizationRole;
use App\Models\Service;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Authorization and tenant isolation.
 *
 * The tenant-isolation tests are the important half. Role checks catch the
 * obvious mistakes; what actually leaks data in production is
 * broken-object-level authorization — a valid token for organization A being
 * used against an id belonging to organization B. Every object-level route is
 * therefore probed across a tenant boundary, not just across a role boundary.
 */
final class IncidentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_viewer_cannot_create_an_incident(): void
    {
        [$organization, $viewer] = $this->tenantWithMember(OrganizationRole::Viewer);

        $response = $this->actingAsMember($viewer, $organization)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/incidents', [
                'title' => 'Something looks wrong',
                'severity' => IncidentSeverity::Sev3->value,
            ]);

        $this->assertApiError($response, 403, 'forbidden');
    }

    public function test_a_reporter_can_create_but_cannot_transition(): void
    {
        [$organization, $reporter] = $this->tenantWithMember(OrganizationRole::Reporter);

        $this->actingAsMember($reporter, $organization)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/incidents', [
                'title' => 'Payments page is blank',
                'severity' => IncidentSeverity::Sev2->value,
            ])
            ->assertCreated();

        $incident = app(IncidentService::class)
            ->create($organization, $reporter, ['title' => 'Another one', 'severity' => IncidentSeverity::Sev3]);

        $response = $this->actingAsMember($reporter, $organization)
            ->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'acknowledged']);

        $this->assertApiError($response, 403, 'forbidden');
    }

    public function test_a_responder_cannot_assign_or_change_severity(): void
    {
        [$organization, $responder] = $this->tenantWithMember(OrganizationRole::Responder);
        $other = User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        $incident = app(IncidentService::class)
            ->create($organization, $responder, ['title' => 'Slow queries', 'severity' => IncidentSeverity::Sev3]);

        $this->assertApiError(
            $this->actingAsMember($responder, $organization)
                ->postJson("/api/v1/incidents/{$incident->id}/assignees", ['user_id' => $other->id]),
            403,
            'forbidden',
        );

        $this->assertApiError(
            $this->actingAsMember($responder, $organization)
                ->postJson("/api/v1/incidents/{$incident->id}/severity", ['severity' => 'sev1']),
            403,
            'forbidden',
        );
    }

    public function test_only_an_administrator_may_delete_an_incident_or_read_the_audit_log(): void
    {
        [$organization, $commander] = $this->tenantWithMember(OrganizationRole::IncidentCommander);
        $admin = User::factory()->memberOf($organization, OrganizationRole::Administrator)->create();

        $incident = app(IncidentService::class)
            ->create($organization, $commander, ['title' => 'Test', 'severity' => IncidentSeverity::Sev4]);

        $this->assertApiError(
            $this->actingAsMember($commander, $organization)->deleteJson("/api/v1/incidents/{$incident->id}"),
            403,
            'forbidden',
        );

        $this->assertApiError(
            $this->actingAsMember($commander, $organization)->getJson('/api/v1/audit-logs'),
            403,
            'forbidden',
        );

        $this->actingAsMember($admin, $organization)->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_an_incident_from_another_tenant_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember(OrganizationRole::Administrator);
        [$organizationB, $userB] = $this->tenantWithMember(OrganizationRole::Administrator);

        $theirs = app(IncidentService::class)
            ->create($organizationB, $userB, ['title' => 'Their outage', 'severity' => IncidentSeverity::Sev1]);

        // 404 rather than 403: confirming the id exists elsewhere is itself a
        // small leak, and there is no reason to hand it out.
        foreach ([
            ['GET', "/api/v1/incidents/{$theirs->id}"],
            ['GET', "/api/v1/incidents/{$theirs->id}/events"],
            ['GET', "/api/v1/incidents/{$theirs->id}/comments"],
            ['PATCH', "/api/v1/incidents/{$theirs->id}"],
            ['DELETE', "/api/v1/incidents/{$theirs->id}"],
        ] as [$method, $uri]) {
            $response = $this->actingAsMember($userA, $organizationA)->json($method, $uri, ['title' => 'Hijacked title']);

            $this->assertContains(
                $response->status(),
                [403, 404],
                "{$method} {$uri} must not succeed across a tenant boundary (got {$response->status()})",
            );
        }

        $this->assertSame('Their outage', $theirs->fresh()?->title);
    }

    public function test_the_incident_list_never_contains_another_tenants_rows(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember();
        [$organizationB, $userB] = $this->tenantWithMember();

        $incidents = app(IncidentService::class);
        $incidents->create($organizationA, $userA, ['title' => 'Ours', 'severity' => IncidentSeverity::Sev3]);
        $incidents->create($organizationB, $userB, ['title' => 'Theirs', 'severity' => IncidentSeverity::Sev3]);

        $response = $this->actingAsMember($userA, $organizationA)->getJson('/api/v1/incidents');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Ours');
    }

    public function test_a_service_from_another_tenant_cannot_be_attached_to_an_incident(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember(OrganizationRole::Reporter);
        [$organizationB] = $this->tenantWithMember();

        $theirService = Service::factory()->create(['organization_id' => $organizationB->id]);

        // Caught by a *scoped* exists rule. A bare `exists:services,id` would
        // accept this and quietly cross-link the two tenants' data.
        $response = $this->actingAsMember($userA, $organizationA)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/incidents', [
                'title' => 'Cross-tenant attempt',
                'severity' => IncidentSeverity::Sev3->value,
                'service_id' => $theirService->id,
            ]);

        $this->assertApiError($response, 422, 'validation_failed');
    }

    public function test_a_request_without_organization_context_is_refused_when_the_user_has_several(): void
    {
        [$organizationA, $user] = $this->tenantWithMember();
        [$organizationB] = $this->tenantWithMember();

        // Same person, two tenants, no header: guessing would silently write
        // an incident into the wrong customer's account.
        $organizationB->members()->create([
            'user_id' => $user->id,
            'role' => OrganizationRole::Responder,
            'joined_at' => now(),
        ]);

        $token = app(TokenService::class)->issueAccessToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->token)
            ->getJson('/api/v1/incidents');

        $this->assertApiError($response, 400, 'organization_required');
    }

    public function test_a_user_cannot_act_in_an_organization_they_do_not_belong_to(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember();
        [$organizationB] = $this->tenantWithMember();

        $response = $this->actingAsMember($userA, $organizationB)->getJson('/api/v1/incidents');

        $this->assertApiError($response, 404, 'organization_not_found');
    }

    public function test_a_member_cannot_grant_a_role_above_their_own_or_change_their_own(): void
    {
        [$organization, $admin] = $this->tenantWithMember(OrganizationRole::Administrator);
        $commander = User::factory()->memberOf($organization, OrganizationRole::IncidentCommander)->create();

        $commanderMembership = $commander->membershipIn($organization);
        $adminMembership = $admin->membershipIn($organization);

        // "Manage members" must not quietly be equivalent to "become the owner".
        $this->assertApiError(
            $this->actingAsMember($commander, $organization)
                ->patchJson("/api/v1/members/{$commanderMembership?->id}", ['role' => 'administrator']),
            403,
            'forbidden',
        );

        // Nobody edits their own role, not even an administrator.
        $this->assertApiError(
            $this->actingAsMember($admin, $organization)
                ->patchJson("/api/v1/members/{$adminMembership?->id}", ['role' => 'viewer']),
            403,
            'forbidden',
        );
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        [$organization, $admin] = $this->tenantWithMember(OrganizationRole::Administrator);
        $secondAdmin = User::factory()->memberOf($organization, OrganizationRole::Administrator)->create();

        // Two administrators: removing the other one is fine.
        $this->actingAsMember($admin, $organization)
            ->deleteJson("/api/v1/members/{$secondAdmin->membershipIn($organization)?->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $secondAdmin->id,
        ]);

        /**
         * With one administrator left, the tenant cannot be stranded.
         *
         * Two independent rules cover this. The policy refuses self-removal, so
         * the last administrator cannot delete their own membership; and
         * `member.manage` is administrator-only, so nobody else can delete it
         * either. The explicit last-administrator count in the controller is
         * defence in depth for the day that permission is granted more widely.
         */
        $lastAdmin = $admin->membershipIn($organization);

        $this->assertApiError(
            $this->actingAsMember($admin, $organization)->deleteJson("/api/v1/members/{$lastAdmin?->id}"),
            403,
            'forbidden',
        );

        $commander = User::factory()->memberOf($organization, OrganizationRole::IncidentCommander)->create();
        $this->assertApiError(
            $this->actingAsMember($commander, $organization)->deleteJson("/api/v1/members/{$lastAdmin?->id}"),
            403,
            'forbidden',
        );

        $this->assertSame(
            1,
            $organization->members()->where('role', OrganizationRole::Administrator->value)->count(),
            'An organization must always keep at least one administrator.',
        );
    }
}
