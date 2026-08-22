<?php

declare(strict_types=1);

namespace App\Enums;

enum PostmortemStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';

    public function isEditable(): bool
    {
        return $this !== self::Published;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Published],
            self::InReview => [self::Draft, self::Published],
            // Publishing is one-way: a published postmortem is a record other
            // teams cite, so corrections are amendments, not silent rewrites.
            self::Published => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
