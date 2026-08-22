<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\IncidentEventType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SendIncidentNotification;
use App\Models\Incident;
use App\Models\Notification;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides who hears about an incident, and records that decision durably
 * before anything is sent.
 *
 * The split between `prepare()` and `dispatch()` is the important part:
 *
 *   prepare()  runs inside the incident's transaction and only writes rows.
 *   dispatch() runs after commit and only enqueues jobs.
 *
 * That ordering is what makes retries safe. A failed email retries the *row*,
 * never the incident update — so "the notification failed" can never become
 * "the incident got resolved twice". It is also why a notification row exists
 * in `pending` even if the queue itself is unreachable: nothing is lost, and a
 * scheduled sweep can pick it up later.
 */
final class NotificationDispatcher
{
    public function __construct(private readonly int $staleAfterMinutes = 5) {}

    /**
     * Creates the notification rows for an event. Call inside the transaction.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Notification>
     */
    public function prepare(
        Incident $incident,
        IncidentEventType $type,
        ?User $actor,
        array $payload = [],
    ): array {
        if (! $type->isNotifiable()) {
            return [];
        }

        $recipients = $this->recipientsFor($incident, $type, $payload)
            // Nobody needs an email telling them what they just did themselves.
            ->reject(fn (int $userId): bool => $actor !== null && $userId === $actor->getKey())
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return [];
        }

        $subject = $this->subjectFor($incident, $type, $actor, $payload);
        $body = $this->bodyFor($incident, $type, $actor, $payload);
        $notifications = [];

        foreach ($recipients as $userId) {
            foreach ($this->channelsFor($incident, $type) as $channel) {
                $notifications[] = Notification::query()->create([
                    'organization_id' => $incident->organization_id,
                    'user_id' => $userId,
                    'incident_id' => $incident->getKey(),
                    'channel' => $channel,
                    'type' => $type->value,
                    'subject' => $subject,
                    'body' => $body,
                    'payload' => [
                        'incident_reference' => $incident->reference,
                        'incident_title' => $incident->title,
                        'severity' => $incident->severity->value,
                        'status' => $incident->status->value,
                        ...$payload,
                    ],
                    /**
                     * An in-app notification is delivered the instant the row
                     * exists — there is no send step for it. Leaving it
                     * `pending` would show the recipient a notification
                     * labelled as not yet delivered, and would misreport the
                     * queue's health to anyone reading these statuses.
                     */
                    'status' => $channel->isQueued()
                        ? NotificationStatus::Pending
                        : NotificationStatus::Sent,
                    'sent_at' => $channel->isQueued() ? null : now(),
                ]);
            }
        }

        return $notifications;
    }

    /**
     * Enqueues delivery. Call *after* the transaction commits — a job that
     * starts before commit can read a row that does not exist yet.
     *
     * @param  list<Notification>  $notifications
     */
    public function dispatch(array $notifications): void
    {
        foreach ($notifications as $notification) {
            if (! $notification->channel->isQueued()) {
                // In-app notifications are already durable the moment the row
                // exists; there is nothing to deliver.
                continue;
            }

            try {
                SendIncidentNotification::dispatch($notification->getKey());
            } catch (Throwable $e) {
                /*
                 * The queue broker is unreachable. This must not propagate.
                 *
                 * By the time we get here the incident and its notification rows
                 * are already committed — dispatch() runs after the transaction
                 * on purpose. Letting this throw would return 500 for a write
                 * that actually succeeded, telling a reporter their SEV-1 was
                 * not filed when it was, and sending them to retry a thing that
                 * already happened.
                 *
                 * The row stays `pending`, which is exactly the state
                 * `notifications:retry-stale` sweeps. That safety net was
                 * already written for this case; it was simply unreachable,
                 * because the failure it catches killed the request first.
                 */
                Log::error('notification.enqueue_failed', [
                    'notification_id' => $notification->getKey(),
                    'incident_id' => $notification->incident_id,
                    'channel' => $notification->channel->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Rows that were written but never made it onto the queue — the queue was
     * down, or the process died between commit and dispatch. Swept by
     * `notifications:retry-stale` so a broker blip does not silently swallow
     * a SEV-1 page.
     *
     * @return Collection<int, Notification>
     */
    public function stalePending(): Collection
    {
        return Notification::query()
            ->where('status', NotificationStatus::Pending->value)
            ->where('channel', NotificationChannel::Email->value)
            ->where('created_at', '<', now()->subMinutes($this->staleAfterMinutes))
            ->limit(500)
            ->get();
    }

    /** @return list<NotificationChannel> */
    private function channelsFor(Incident $incident, IncidentEventType $type): array
    {
        $channels = [NotificationChannel::InApp];

        // Email is an interruption. Reserve it for events that genuinely need
        // to reach someone who is not looking at the dashboard.
        if ($incident->severity->requiresImmediateNotification() || $type === IncidentEventType::Assigned) {
            $channels[] = NotificationChannel::Email;
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, int>
     */
    private function recipientsFor(Incident $incident, IncidentEventType $type, array $payload): Collection
    {
        // A newly assigned responder is the only person who needs to know
        // about their own assignment; broadcasting it to the room is noise.
        if ($type === IncidentEventType::Assigned) {
            $assigneeId = $payload['assignee_id'] ?? null;

            return collect(is_int($assigneeId) ? [$assigneeId] : []);
        }

        $involved = collect([
            $incident->commander_id,
            $incident->reported_by,
        ])->merge($incident->assignments()->pluck('user_id'))
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id);

        $escalated = $type === IncidentEventType::SeverityChanged
            && in_array($payload['to'] ?? null, ['sev1', 'sev2'], strict: true);

        // A new high-severity incident, or an escalation into one, wakes the
        // whole on-call population rather than only whoever happens to be
        // attached to the record already.
        if (($type === IncidentEventType::Created && $incident->severity->requiresImmediateNotification()) || $escalated) {
            $involved = $involved->merge($this->respondersIn($incident->organization_id));
        }

        return $involved;
    }

    /** @return Collection<int, int> */
    private function respondersIn(int $organizationId): Collection
    {
        $eligible = array_values(array_map(
            static fn (OrganizationRole $role): string => $role->value,
            array_filter(OrganizationRole::cases(), static fn (OrganizationRole $role): bool => $role->canBeAssigned()),
        ));

        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->whereIn('role', $eligible)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id);
    }

    /** @param array<string, mixed> $payload */
    private function subjectFor(Incident $incident, IncidentEventType $type, ?User $actor, array $payload): string
    {
        $prefix = sprintf('[%s] %s', strtoupper($incident->severity->value), $incident->reference);

        return match ($type) {
            IncidentEventType::Created => "{$prefix} opened: {$incident->title}",
            IncidentEventType::Resolved => "{$prefix} resolved: {$incident->title}",
            IncidentEventType::Reopened => "{$prefix} reopened: {$incident->title}",
            IncidentEventType::Assigned => "{$prefix} you were assigned: {$incident->title}",
            IncidentEventType::SeverityChanged => "{$prefix} severity changed: {$incident->title}",
            default => "{$prefix} updated: {$incident->title}",
        };
    }

    /** @param array<string, mixed> $payload */
    private function bodyFor(Incident $incident, IncidentEventType $type, ?User $actor, array $payload): string
    {
        return $type->describe($actor?->name ?? 'System', $payload);
    }
}
