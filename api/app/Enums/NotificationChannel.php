<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case InApp = 'in_app';

    /** In-app notifications are written synchronously; email is always queued. */
    public function isQueued(): bool
    {
        return $this === self::Email;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
