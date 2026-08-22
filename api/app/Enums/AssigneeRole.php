<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seat a person occupies *on a specific incident*, distinct from their
 * organization role. A responder can be the scribe on one incident and the
 * subject-matter expert on another.
 */
enum AssigneeRole: string
{
    case Responder = 'responder';
    case Scribe = 'scribe';
    case Communications = 'communications';
    case SubjectMatterExpert = 'subject_matter_expert';

    public function label(): string
    {
        return match ($this) {
            self::Responder => 'Responder',
            self::Scribe => 'Scribe',
            self::Communications => 'Communications Lead',
            self::SubjectMatterExpert => 'Subject-Matter Expert',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
