<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Enums\IncidentSeverity;
use App\Enums\OrganizationRole;
use App\Models\IdempotencyKey;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Idempotent incident creation.
 *
 * The scenario being defended against is mundane and constant: a responder
 * taps "Report incident" on a phone with one bar, the request succeeds, the
 * response is lost, the client retries. Without a key that produces two SEV-1
 * records for one outage — two pages, two commanders, and two people fighting
 * the same fire from different rows.
 */
final class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_creation_requires_an_idempotency_key(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);

        $response = $this->actingAsMember($user, $organization)->postJson('/api/v1/incidents', [
            'title' => 'Something is broken',
            'severity' => IncidentSeverity::Sev3->value,
        ]);

        $this->assertApiError($response, 400, 'idempotency.key_required');
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_replaying_the_same_key_returns_the_original_response_without_creating_a_second_incident(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);
        $key = (string) Str::uuid();

        $payload = [
            'title' => 'Checkout is down for everyone',
            'severity' => IncidentSeverity::Sev1->value,
        ];

        $first = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', $payload);

        $first->assertCreated();

        $second = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', $payload);

        $second->assertCreated();
        $second->assertHeader('Idempotent-Replayed', 'true');

        // Byte-identical body, and crucially only one incident.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, Incident::query()->count());
        // The replay must not re-run the side effects either.
        $this->assertSame(1, IncidentEvent::query()->count());
    }

    public function test_reusing_a_key_with_a_different_body_is_rejected_rather_than_silently_returning_the_first(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);
        $key = (string) Str::uuid();

        $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'Original incident', 'severity' => 'sev2'])
            ->assertCreated();

        // Returning the first incident here would hide a genuine client bug
        // behind a plausible-looking success.
        $response = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'A completely different incident', 'severity' => 'sev4']);

        $this->assertApiError($response, 422, 'idempotency.payload_mismatch');
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_key_ordering_within_the_body_does_not_change_the_fingerprint(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);
        $key = (string) Str::uuid();

        $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'Ordering test incident', 'severity' => 'sev3'])
            ->assertCreated();

        // A client that serialises its object in a different order on retry is
        // sending the same request; the hash must agree.
        $replay = $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['severity' => 'sev3', 'title' => 'Ordering test incident']);

        $replay->assertCreated();
        $replay->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_a_failed_request_releases_its_key_so_a_genuine_retry_can_succeed(): void
    {
        [$organization, $user] = $this->tenantWithMember(OrganizationRole::Reporter);
        $key = (string) Str::uuid();

        // Fails validation: title is too short.
        $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'no', 'severity' => 'sev3'])
            ->assertStatus(422);

        // Holding the key after a failure would strand a client that simply
        // corrected its input and retried with the same key.
        $this->assertSame(0, IdempotencyKey::query()->count());

        $this->actingAsMember($user, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'Corrected and valid title', 'severity' => 'sev3'])
            ->assertCreated();
    }

    public function test_keys_are_scoped_per_user_so_two_people_can_use_the_same_value(): void
    {
        [$organization, $first] = $this->tenantWithMember(OrganizationRole::Reporter);
        $second = User::factory()->memberOf($organization, OrganizationRole::Reporter)->create();

        $key = 'shared-client-generated-key';

        $this->actingAsMember($first, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'First reporter incident', 'severity' => 'sev3'])
            ->assertCreated();

        // Uniqueness is (user, endpoint, key). Two clients that happen to
        // generate the same value must not collide.
        $this->actingAsMember($second, $organization)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/incidents', ['title' => 'Second reporter incident', 'severity' => 'sev3'])
            ->assertCreated();

        $this->assertSame(2, Incident::query()->count());
    }
}
