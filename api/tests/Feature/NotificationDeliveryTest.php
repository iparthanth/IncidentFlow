<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SendIncidentNotification;
use App\Mail\IncidentNotificationMail;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Notification delivery, and the separation that makes retries safe.
 *
 * The failure being designed against: an email provider times out, the job
 * retries, and the retry re-runs the incident update — so the incident gets
 * resolved twice, the timeline gains a duplicate entry, and everyone is paged
 * again. The rows-then-jobs split is what prevents it, and these tests are what
 * stop a future refactor from quietly collapsing the two phases back together.
 */
final class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_high_severity_incident_writes_notification_rows_before_anything_is_sent(): void
    {
        Queue::fake();

        [$organization, $reporter] = $this->tenantWithMember(OrganizationRole::Reporter);
        User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        app(IncidentService::class)->create($organization, $reporter, [
            'title' => 'Checkout is down for everyone',
            'severity' => IncidentSeverity::Sev1,
        ]);

        // Email rows exist and are queued but undelivered. That ordering is
        // what makes a lost queue recoverable instead of silent.
        $emails = Notification::query()->where('channel', NotificationChannel::Email->value)->get();
        $this->assertGreaterThan(0, $emails->count());
        $this->assertTrue($emails->every(fn ($n) => $n->status === NotificationStatus::Pending));
        $this->assertTrue($emails->every(fn ($n) => $n->sent_at === null));

        // In-app rows have no send step — the row itself is the delivery, so
        // resting them at `pending` would mislabel them in the recipient's
        // inbox and misreport the queue's health to anyone reading statuses.
        $inApp = Notification::query()->where('channel', NotificationChannel::InApp->value)->get();
        $this->assertGreaterThan(0, $inApp->count());
        $this->assertTrue($inApp->every(fn ($n) => $n->status === NotificationStatus::Sent));
        $this->assertTrue($inApp->every(fn ($n) => $n->sent_at !== null));

        Queue::assertPushed(SendIncidentNotification::class);
    }

    public function test_a_low_severity_incident_does_not_send_email(): void
    {
        [$organization, $reporter] = $this->tenantWithMember(OrganizationRole::Reporter);
        User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        app(IncidentService::class)->create($organization, $reporter, [
            'title' => 'Dashboard legend is misaligned',
            'severity' => IncidentSeverity::Sev4,
        ]);

        // Email is an interruption. A SEV-4 gets an in-app record and nothing
        // that wakes anyone up.
        $this->assertSame(
            0,
            Notification::query()->where('channel', NotificationChannel::Email->value)->count(),
        );
    }

    public function test_the_job_moves_one_row_from_pending_to_sent(): void
    {
        Mail::fake();

        $notification = $this->pendingNotification();

        (new SendIncidentNotification($notification->getKey()))->handle();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame(1, $notification->attempts);

        Mail::assertSent(IncidentNotificationMail::class);
    }

    public function test_running_the_job_twice_does_not_send_twice(): void
    {
        Mail::fake();

        $notification = $this->pendingNotification();
        $job = new SendIncidentNotification($notification->getKey());

        $job->handle();
        // A duplicate dispatch — the stale sweeper racing the original, say —
        // must be a no-op rather than a second page.
        $job->handle();

        Mail::assertSentCount(1);
        $this->assertSame(1, $notification->refresh()->attempts);
    }

    public function test_a_retry_redelivers_the_notification_without_touching_the_incident(): void
    {
        Mail::fake();

        [$notification, $incident] = $this->pendingNotificationWithIncident();

        $eventsBefore = IncidentEvent::query()->count();
        $statusBefore = $incident->status;
        $updatedBefore = $incident->updated_at;

        // Three attempts, as a failing provider would produce.
        $job = new SendIncidentNotification($notification->getKey());
        $job->handle();
        $notification->forceFill(['status' => NotificationStatus::Pending, 'sent_at' => null])->save();
        $job->handle();

        $incident->refresh();

        // This is the whole point: retrying delivery cannot re-resolve an
        // incident, duplicate a timeline entry, or move a clock.
        $this->assertSame($eventsBefore, IncidentEvent::query()->count());
        $this->assertSame($statusBefore, $incident->status);
        $this->assertEquals($updatedBefore, $incident->updated_at);
    }

    public function test_a_failed_send_is_recorded_on_the_row_not_only_in_failed_jobs(): void
    {
        Log::spy();

        $notification = $this->pendingNotification();

        (new SendIncidentNotification($notification->getKey()))
            ->failed(new RuntimeException('SMTP connection refused'));

        $notification->refresh();

        // An operator looking for "did that page go out?" is looking in the
        // product, not in the failed_jobs table.
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertStringContainsString('SMTP connection refused', (string) $notification->last_error);
    }

    public function test_an_inactive_recipient_fails_the_row_rather_than_the_job(): void
    {
        Mail::fake();

        $notification = $this->pendingNotification();
        $notification->recipient?->forceFill(['is_active' => false])->save();

        (new SendIncidentNotification($notification->getKey()))->handle();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        Mail::assertNothingSent();
    }

    public function test_a_missing_notification_is_a_no_op_rather_than_a_failure(): void
    {
        Mail::fake();

        // The row was pruned, or the incident hard-deleted. Throwing here would
        // only fill the failed-jobs table with noise nobody can action.
        (new SendIncidentNotification(999_999))->handle();

        Mail::assertNothingSent();
    }

    public function test_the_sweeper_finds_rows_that_never_reached_the_queue(): void
    {
        Queue::fake();

        $notification = $this->pendingNotification();

        // Simulates the process dying between the commit and the dispatch: the
        // row exists, no job was ever created, and a SEV-1 page silently never
        // arrives. This is the worst failure mode the system has.
        $notification->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $stale = app(NotificationDispatcher::class)->stalePending();

        $this->assertTrue($stale->contains('id', $notification->getKey()));

        $this->artisan('notifications:retry-stale')
            ->expectsOutputToContain('Re-queued')
            ->assertSuccessful();

        Queue::assertPushed(SendIncidentNotification::class);
    }

    public function test_the_sweeper_ignores_rows_that_are_merely_recent(): void
    {
        $this->pendingNotification();

        // A row created moments ago is almost certainly still in flight;
        // re-queuing it would be the duplicate the design avoids elsewhere.
        $this->assertSame(0, app(NotificationDispatcher::class)->stalePending()->count());
    }

    public function test_a_resolution_notifies_the_people_involved_but_not_the_actor(): void
    {
        Queue::fake();

        [$organization, $reporter] = $this->tenantWithMember(OrganizationRole::Reporter);
        $responder = User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        $incidents = app(IncidentService::class);
        $incident = $incidents->create($organization, $reporter, [
            'title' => 'Payments degraded',
            'severity' => IncidentSeverity::Sev2,
        ]);

        Notification::query()->delete();

        $incidents->transition($incident, $responder, IncidentStatus::Acknowledged);

        // The reporter hears about it; the responder who did it does not need
        // an email telling them what they just did.
        $this->assertTrue(Notification::query()->where('user_id', $reporter->id)->exists());
        $this->assertFalse(Notification::query()->where('user_id', $responder->id)->exists());
    }

    // --------------------------------------------------------------- helpers

    private function pendingNotification(): Notification
    {
        [$notification] = $this->pendingNotificationWithIncident();

        return $notification;
    }

    /** @return array{Notification, Incident} */
    private function pendingNotificationWithIncident(): array
    {
        [$organization, $reporter] = $this->tenantWithMember(OrganizationRole::Reporter);
        $recipient = User::factory()->memberOf($organization, OrganizationRole::Responder)->create();

        // SEV-3 deliberately: a high-severity create would fan out its own
        // emails through the sync queue, and the row under test would then be
        // competing with them for the mail fake's assertions.
        $incident = app(IncidentService::class)->create($organization, $reporter, [
            'title' => 'Checkout is degraded',
            'severity' => IncidentSeverity::Sev3,
        ]);

        $notification = Notification::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $recipient->getKey(),
            'incident_id' => $incident->getKey(),
            'channel' => NotificationChannel::Email,
            'type' => 'incident.created',
            'subject' => '[SEV3] INC-0001 opened',
            'body' => 'Checkout is degraded',
            'payload' => ['incident_reference' => $incident->reference],
            'status' => NotificationStatus::Pending,
        ]);

        return [$notification, $incident];
    }
}
