<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every kind of entry that can appear on the append-only timeline.
 *
 * These strings are a *public contract*: they are the `event:` name on the SSE
 * stream and the discriminator the React client switches on. Renaming one is a
 * breaking change, which is exactly why they live in an enum instead of being
 * typed as literals at each call site.
 */
enum IncidentEventType: string
{
    case Created = 'incident.created';
    case StatusChanged = 'incident.status_changed';
    case SeverityChanged = 'incident.severity_changed';
    case Updated = 'incident.updated';
    case Assigned = 'incident.assigned';
    case Unassigned = 'incident.unassigned';
    case CommanderChanged = 'incident.commander_changed';
    case Commented = 'incident.commented';
    case UpdatePosted = 'incident.update_posted';
    case Acknowledged = 'incident.acknowledged';
    case Mitigated = 'incident.mitigated';
    case Resolved = 'incident.resolved';
    case Closed = 'incident.closed';
    case Reopened = 'incident.reopened';
    case PostmortemDrafted = 'postmortem.drafted';
    case PostmortemPublished = 'postmortem.published';
    case Deleted = 'incident.deleted';
    case Restored = 'incident.restored';

    /** Human-readable sentence used in digests and email bodies. */
    public function describe(string $actor, array $payload = []): string
    {
        return match ($this) {
            self::Created => "{$actor} reported the incident",
            self::StatusChanged => sprintf(
                '%s changed status from %s to %s',
                $actor,
                $payload['from'] ?? 'unknown',
                $payload['to'] ?? 'unknown',
            ),
            self::SeverityChanged => sprintf(
                '%s changed severity from %s to %s',
                $actor,
                strtoupper((string) ($payload['from'] ?? 'unknown')),
                strtoupper((string) ($payload['to'] ?? 'unknown')),
            ),
            self::Updated => "{$actor} edited the incident details",
            self::Assigned => sprintf('%s assigned %s', $actor, $payload['assignee_name'] ?? 'a responder'),
            self::Unassigned => sprintf('%s unassigned %s', $actor, $payload['assignee_name'] ?? 'a responder'),
            self::CommanderChanged => sprintf('%s handed command to %s', $actor, $payload['commander_name'] ?? 'a commander'),
            self::Commented => "{$actor} commented",
            self::UpdatePosted => "{$actor} posted an update",
            self::Acknowledged => "{$actor} acknowledged the incident",
            self::Mitigated => "{$actor} marked the incident mitigated",
            self::Resolved => "{$actor} resolved the incident",
            self::Closed => "{$actor} closed the incident",
            self::Reopened => "{$actor} reopened the incident",
            self::PostmortemDrafted => "{$actor} started the postmortem",
            self::PostmortemPublished => "{$actor} published the postmortem",
            self::Deleted => "{$actor} deleted the incident",
            self::Restored => "{$actor} restored the incident",
        };
    }

    /**
     * Events worth interrupting a human for.
     *
     * Every status transition is included, `Acknowledged` and `Mitigated`
     * among them. It is tempting to treat those as internal chatter, but the
     * person who reported an incident is waiting to hear that somebody picked
     * it up — leaving them to guess is how you get "any update?" messages in
     * three channels while responders are trying to work.
     *
     * Note that being notifiable does not mean sending email: `channelsFor()`
     * reserves that for high severity and direct assignment, so a SEV-4 moving
     * to mitigated produces an in-app record and nothing that pages anyone.
     */
    public function isNotifiable(): bool
    {
        return in_array($this, [
            self::Created,
            self::StatusChanged,
            self::SeverityChanged,
            self::Assigned,
            self::Acknowledged,
            self::Mitigated,
            self::Resolved,
            self::Reopened,
        ], strict: true);
    }

    /** Maps a status transition to its dedicated timeline event, if one exists. */
    public static function forStatus(IncidentStatus $status): ?self
    {
        return match ($status) {
            IncidentStatus::Acknowledged => self::Acknowledged,
            IncidentStatus::Mitigated => self::Mitigated,
            IncidentStatus::Resolved => self::Resolved,
            IncidentStatus::Closed => self::Closed,
            IncidentStatus::Open => null,
        };
    }
}
