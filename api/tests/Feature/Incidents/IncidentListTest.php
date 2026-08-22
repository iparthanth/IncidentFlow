<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Enums\IncidentSeverity;
use App\Models\Incident;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listing, filtering, sorting and pagination — and the input validation that
 * keeps all three from becoming attack surface.
 */
final class IncidentListTest extends TestCase
{
    use RefreshDatabase;

    public function test_incidents_can_be_filtered_by_status_severity_and_service(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        $service = Service::factory()->create(['organization_id' => $organization->id]);

        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev1)->create([
            'title' => 'Critical open',
            'service_id' => $service->id,
        ]);
        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev4)->resolved()->create([
            'title' => 'Minor resolved',
        ]);

        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?severity[]=sev1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Critical open');

        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?status[]=resolved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Minor resolved');

        $this->actingAsMember($user, $organization)
            ->getJson("/api/v1/incidents?service_id={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?active_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Critical open');
    }

    public function test_search_matches_title_reference_and_description_case_insensitively(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        Incident::factory()->forOrganization($organization)->create([
            'title' => 'Kafka consumer lag',
            'description' => 'Partition rebalancing storm',
        ]);
        Incident::factory()->forOrganization($organization)->create(['title' => 'Unrelated']);

        // `whereLike(caseSensitive: false)` compiles to ILIKE on PostgreSQL and
        // LIKE on SQLite, so this behaves the same in CI and in production.
        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?q=KAFKA')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?q=rebalancing')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_wildcard_in_the_search_term_is_treated_as_a_literal(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        Incident::factory()->forOrganization($organization)->create(['title' => 'Alpha']);
        Incident::factory()->forOrganization($organization)->create(['title' => 'Beta']);

        // Unescaped, "%" would match every incident — a small but real way for
        // a search box to become a full table dump.
        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_boolean_query_parameters_accept_the_spellings_clients_actually_send(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        Incident::factory()->forOrganization($organization)->create(['title' => 'Still burning']);
        Incident::factory()->forOrganization($organization)->resolved()->create(['title' => 'Finished']);

        // Laravel's `boolean` rule rejects the string "true", but that is what
        // every HTTP client produces for a flag. Rejecting it would be a 422 on
        // a technicality, so the request is normalised before validation runs.
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $truthy) {
            $this->actingAsMember($user, $organization)
                ->getJson("/api/v1/incidents?active_only={$truthy}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.title', 'Still burning');
        }

        foreach (['0', 'false', 'no', 'off'] as $falsy) {
            $this->actingAsMember($user, $organization)
                ->getJson("/api/v1/incidents?active_only={$falsy}")
                ->assertOk()
                ->assertJsonCount(2, 'data');
        }
    }

    public function test_a_nonsense_boolean_is_still_rejected_rather_than_silently_coerced(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // Normalising "banana" to false would hide a client bug behind a
        // plausible-looking result set.
        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?active_only=banana');

        $this->assertApiError($response, 422, 'validation_failed');
    }

    public function test_an_unknown_sort_column_is_rejected_rather_than_ignored(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // An unvalidated sort parameter is a column name injected into ORDER BY.
        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?sort=password');

        $this->assertApiError($response, 422, 'validation_failed');
    }

    public function test_page_size_is_capped(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // `?per_page=100000` is a denial-of-service request wearing a
        // pagination parameter as a disguise.
        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?per_page=100000');

        $this->assertApiError($response, 422, 'validation_failed');
    }

    public function test_results_are_sorted_and_paginated_with_a_stable_tiebreaker(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        // Identical timestamps: without the id tiebreaker these could reshuffle
        // between page requests, showing one incident twice and hiding another.
        $sharedTimestamp = now()->subDay();
        foreach (range(1, 5) as $index) {
            Incident::factory()->forOrganization($organization)->create([
                'title' => "Incident {$index}",
                'created_at' => $sharedTimestamp,
            ]);
        }

        $firstPage = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?per_page=2&page=1')
            ->assertOk();

        $secondPage = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?per_page=2&page=2')
            ->assertOk();

        $firstIds = collect($firstPage->json('data'))->pluck('id')->all();
        $secondIds = collect($secondPage->json('data'))->pluck('id')->all();

        $this->assertCount(2, $firstIds);
        $this->assertCount(2, $secondIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'Pages must not overlap.');
        $this->assertSame(5, $firstPage->json('meta.total'));
    }

    public function test_sorting_by_severity_puts_the_most_urgent_first(): void
    {
        [$organization, $user] = $this->tenantWithMember();

        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev4)->create();
        Incident::factory()->forOrganization($organization)->severity(IncidentSeverity::Sev1)->create();

        $response = $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents?sort=severity&direction=asc')
            ->assertOk();

        $this->assertSame('sev1', $response->json('data.0.severity.value'));
    }

    public function test_the_list_reports_which_transitions_are_available(): void
    {
        [$organization, $user] = $this->tenantWithMember();
        Incident::factory()->forOrganization($organization)->create();

        // The client renders exactly the buttons the state machine accepts,
        // rather than showing all five and learning the rules through 422s.
        $this->actingAsMember($user, $organization)
            ->getJson('/api/v1/incidents')
            ->assertOk()
            ->assertJsonPath('data.0.status.allowed_transitions', ['acknowledged', 'resolved']);
    }
}
