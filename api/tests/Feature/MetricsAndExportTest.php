<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentSeverity;
use App\Enums\OrganizationRole;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_mtta_and_mttr_are_averaged_from_the_stored_durations(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // 5 min / 60 min, and 15 min / 120 min → MTTA 10 min, MTTR 90 min.
        Incident::factory()->forOrganization($organization)
            ->resolved(acknowledgeAfter: 300, resolveAfter: 3_600)
            ->create(['created_at' => now()->subDays(2)]);

        Incident::factory()->forOrganization($organization)
            ->resolved(acknowledgeAfter: 900, resolveAfter: 7_200)
            ->create(['created_at' => now()->subDays(1)]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary?days=30')
            ->assertOk();

        $this->assertSame(600, $response->json('data.mtta_seconds.average'));
        $this->assertSame(5_400, $response->json('data.mttr_seconds.average'));
        $this->assertSame(2, $response->json('data.totals.created'));
        $this->assertSame(2, $response->json('data.totals.resolved'));
    }

    public function test_every_numeric_field_is_a_whole_number(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        Incident::factory()->forOrganization($organization)
            ->resolved()
            ->create(['created_at' => now()->subDay()]);

        $data = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary?days=30')
            ->assertOk()
            ->json('data');

        // Carbon's diff methods return floats. A period of "30.2587 days" is
        // meaningless to a reader and rejected outright by a typed client.
        $this->assertIsInt($data['period']['days']);

        foreach (['mtta_seconds', 'mttr_seconds'] as $metric) {
            foreach (['count', 'average', 'p50', 'p90', 'p95', 'max'] as $field) {
                $value = $data[$metric][$field];
                $this->assertTrue(
                    $value === null || is_int($value),
                    "{$metric}.{$field} should be an integer or null, got ".get_debug_type($value),
                );
            }
        }
    }

    public function test_percentiles_are_reported_alongside_the_average(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // One very slow incident among nine fast ones: the mean stays
        // respectable while p95 tells the truth.
        foreach (range(1, 9) as $i) {
            Incident::factory()->forOrganization($organization)
                ->resolved(acknowledgeAfter: 60, resolveAfter: 600)
                ->create(['created_at' => now()->subDays(3)]);
        }

        Incident::factory()->forOrganization($organization)
            ->resolved(acknowledgeAfter: 60, resolveAfter: 36_000)
            ->create(['created_at' => now()->subDays(3)]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary?days=30')
            ->assertOk();

        $this->assertSame(600, $response->json('data.mttr_seconds.p50'));
        $this->assertSame(36_000, $response->json('data.mttr_seconds.p95'));
        $this->assertSame(36_000, $response->json('data.mttr_seconds.max'));
        $this->assertLessThan(
            $response->json('data.mttr_seconds.p95'),
            $response->json('data.mttr_seconds.average'),
            'The average must not be reported as if it were the worst case.',
        );
    }

    public function test_acknowledgement_sla_attainment_is_measured_against_the_severity_target(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // SEV-1 target is 5 minutes. One inside it, one well outside.
        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev1)
            ->acknowledged(afterSeconds: 120)
            ->create(['created_at' => now()->subDay()]);

        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev1)
            ->acknowledged(afterSeconds: 1_800)
            ->create(['created_at' => now()->subDay()]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary?days=30')
            ->assertOk();

        // JSON has one number type, so 50.0 comes back as an int — compare loosely.
        $this->assertEquals(50, $response->json('data.acknowledgement_sla.overall_attainment'));
        $this->assertSame(5, $response->json('data.acknowledgement_sla.by_severity.sev1.target_minutes'));
    }

    public function test_severity_and_status_buckets_are_zero_filled(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        // Pinned inside the default 30-day window; the factory otherwise
        // scatters created_at over 60 days and the row may fall outside it.
        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev3)
            ->create(['created_at' => now()->subDay()]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary')
            ->assertOk();

        // Omitting empty categories makes a chart's axes move between requests.
        $this->assertSame(0, $response->json('data.by_severity.sev1'));
        $this->assertSame(1, $response->json('data.by_severity.sev3'));
        $this->assertSame(0, $response->json('data.by_status.closed'));
    }

    public function test_the_trend_series_includes_quiet_days(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        Incident::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(3)]);

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/trends?days=7')
            ->assertOk();

        $series = $response->json('data.series');
        $this->assertGreaterThanOrEqual(7, count($series));
        // Skipping empty days would imply continuous activity where there was none.
        $this->assertContains(0, array_column($series, 'created'));
    }

    public function test_an_absurd_reporting_window_is_rejected(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/metrics/summary?days=99999');

        $this->assertApiError($response, 422, 'validation_failed');
    }

    public function test_csv_export_neutralises_spreadsheet_formula_injection(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::IncidentCommander);

        // Inert in the database, inert in JSON — and then executed by Excel
        // when somebody opens the export. This endpoint is where it is
        // introduced, so this is where it has to be stopped.
        Incident::factory()->forOrganization($organization)->create([
            'title' => '=cmd|\' /C calc\'!A0',
            'reference' => 'INC-9001',
        ]);

        $response = $this->actingAsMember($user, $organization)->get('/api/v1/incidents/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=cmd", $csv, 'A leading = must be prefixed so the cell stays text.');
        $this->assertStringNotContainsString(',=cmd', $csv);
        // UTF-8 BOM, or Excel on Windows renders non-ASCII names as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_the_export_applies_every_filter_the_list_does(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::IncidentCommander);

        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev1)
            ->create(['title' => 'Still burning', 'reference' => 'INC-8001']);
        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev4)
            ->resolved()->create(['title' => 'Long finished', 'reference' => 'INC-8002']);

        $rows = function (string $query) use ($user, $organization): string {
            return $this->actingAsMember($user, $organization)
                ->get("/api/v1/incidents/export{$query}")
                ->assertOk()
                ->streamedContent();
        };

        // A spreadsheet that disagrees with the screen it came from is worse
        // than no export at all — the user will trust the spreadsheet.
        $active = $rows('?active_only=true');
        $this->assertStringContainsString('INC-8001', $active);
        $this->assertStringNotContainsString('INC-8002', $active);

        $bySeverity = $rows('?severity[]=sev4');
        $this->assertStringContainsString('INC-8002', $bySeverity);
        $this->assertStringNotContainsString('INC-8001', $bySeverity);

        $unfiltered = $rows('');
        $this->assertStringContainsString('INC-8001', $unfiltered);
        $this->assertStringContainsString('INC-8002', $unfiltered);
    }

    public function test_export_is_refused_to_roles_without_the_permission_and_recorded_for_those_with_it(): void
    {
        [$organization, $responder] = $this->tenantWithMember(OrganizationRole::Responder);
        $commander = User::factory()
            ->memberOf($organization, OrganizationRole::IncidentCommander)
            ->create();

        $this->assertApiError(
            $this->actingAsMember($responder, $organization)->getJson('/api/v1/incidents/export'),
            403,
            'forbidden',
        );

        $this->actingAsMember($commander, $organization)->get('/api/v1/incidents/export')->assertOk();

        // An export is a data-egress event; the trail should show who took what.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incident.exported',
            'actor_id' => $commander->id,
        ]);
    }

    public function test_metrics_are_scoped_to_the_calling_tenant(): void
    {
        [$organizationA, $userA] = $this->tenantWithMember();
        [$organizationB] = $this->tenantWithMember();

        // Pinned inside the default 30-day window. The factory otherwise
        // scatters created_at across 60 days, which made this pass or fail
        // depending on the random value it happened to pick.
        Incident::factory()->forOrganization($organizationA)->create(['created_at' => now()->subDay()]);
        Incident::factory()->count(5)->forOrganization($organizationB)->create(['created_at' => now()->subDay()]);

        $response = $this->actingAsMember($userA, $organizationA)
            ->getJson('/api/v1/metrics/summary')
            ->assertOk();

        $this->assertSame(1, $response->json('data.totals.created'));
    }
}
