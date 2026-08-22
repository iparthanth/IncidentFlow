<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery state of a single notification row.
 *
 * The row is created *before* the job is dispatched, so a notification that
 * never reaches the queue is still visible as `pending` rather than vanishing.
 * Retries mutate this row; they never re-run the incident update that caused it.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Read = 'read';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Read], strict: true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
