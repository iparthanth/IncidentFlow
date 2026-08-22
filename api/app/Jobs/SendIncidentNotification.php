<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Mail\IncidentNotificationMail;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one notification row.
 *
 * The job takes an **id, not a model**. A serialised model is a snapshot of
 * the row at dispatch time; by the time a retry runs minutes later the row may
 * have been marked read, or the incident resolved. Re-reading inside the job
 * means every attempt works from current truth.
 *
 * Its only responsibility is delivery. It never re-applies the incident change
 * that caused the notification — which is precisely how a failed email retries
 * five times without the incident being resolved five times.
 */
final class SendIncidentNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Exponential backoff. A mail provider that just rate-limited us will not
     * be happier if we retry in one second, and hammering it makes recovery
     * slower for everyone in the queue.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 300];

    public int $tries = 5;

    /** Beyond this the send is stale enough that nobody wants it any more. */
    public int $timeout = 30;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = Notification::query()->with(['recipient', 'incident'])->find($this->notificationId);

        if ($notification === null) {
            // The row was pruned or the incident hard-deleted. Nothing to do,
            // and failing would only fill the failed-jobs table with noise.
            return;
        }

        // Terminal states are terminal. A duplicate dispatch — from the stale
        // sweeper racing the original dispatch, say — must not send twice.
        if ($notification->status === NotificationStatus::Sent || $notification->read_at !== null) {
            return;
        }

        $recipient = $notification->recipient;

        if ($recipient === null || ! $recipient->is_active || $recipient->email === '') {
            $notification->forceFill([
                'status' => NotificationStatus::Failed,
                'last_error' => 'Recipient is missing or inactive',
                'attempts' => $notification->attempts + 1,
            ])->save();

            return;
        }

        $notification->forceFill([
            'status' => NotificationStatus::Queued,
            'attempts' => $notification->attempts + 1,
        ])->save();

        Mail::to($recipient->email)->send(new IncidentNotificationMail($notification));

        $notification->forceFill([
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();

        Log::info('notification.sent', [
            'notification_id' => $notification->ulid,
            'incident_id' => $notification->incident_id,
            'channel' => $notification->channel->value,
            'attempts' => $notification->attempts,
        ]);
    }

    /**
     * Called after the final attempt. Recording the failure on the row — rather
     * than only in the failed_jobs table — is what lets an operator see "this
     * page never reached anyone" in the product, which is the only place they
     * will be looking during an incident.
     */
    public function failed(?Throwable $exception): void
    {
        $notification = Notification::query()->find($this->notificationId);

        $notification?->forceFill([
            'status' => NotificationStatus::Failed,
            'last_error' => mb_substr($exception?->getMessage() ?? 'Unknown failure', 0, 1000),
        ])->save();

        Log::error('notification.failed', [
            'notification_id' => $this->notificationId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['notification', 'notification:'.$this->notificationId];
    }
}
