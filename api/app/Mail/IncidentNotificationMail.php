<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The incident email.
 *
 * The two `Headers` below are what make a stream of updates about one incident
 * collapse into a single mail thread instead of fifteen separate messages:
 * `Message-ID` is derived from the notification's ULID, and `References` points
 * at the incident. During a SEV-1, an inbox that threads is the difference
 * between a readable timeline and an unusable wall of alerts.
 */
final class IncidentNotificationMail extends Mailable
{
    public function __construct(public readonly Notification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->subject ?? 'Incident update',
            tags: ['incident-notification'],
            metadata: [
                'notification_id' => $this->notification->ulid,
                'incident_id' => (string) ($this->notification->incident_id ?? ''),
            ],
        );
    }

    public function headers(): Headers
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'incidentflow.local';
        $incidentRef = $this->notification->incident_id ?? 'none';

        return new Headers(
            messageId: sprintf('%s@%s', $this->notification->ulid, $domain),
            references: [sprintf('incident-%s@%s', $incidentRef, $domain)],
            text: [
                // Lets recipients mute one incident without muting the product.
                'X-Incident-Reference' => (string) ($this->notification->payload['incident_reference'] ?? ''),
                'X-Incident-Severity' => (string) ($this->notification->payload['severity'] ?? ''),
                'Auto-Submitted' => 'auto-generated',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.incident-notification',
            with: [
                'notification' => $this->notification,
                'incident' => $this->notification->incident,
                'payload' => $this->notification->payload ?? [],
                'url' => rtrim((string) config('app.frontend_url'), '/')
                    .'/incidents/'.($this->notification->incident_id ?? ''),
            ],
        );
    }
}
