<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PostmortemException extends DomainException
{
    /** @param list<string> $missing */
    public static function incomplete(array $missing): self
    {
        return new self(
            'The postmortem cannot be published until every required section is filled in.',
            'postmortem.incomplete',
            422,
            ['missing_sections' => $missing],
        );
    }
}
