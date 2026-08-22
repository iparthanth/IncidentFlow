<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The complete set of things a member can be allowed to do.
 *
 * Policies check permissions, never roles. That indirection is what lets a new
 * role be introduced by editing one map instead of auditing every `if` in the
 * codebase — and it makes "what can a responder actually do?" a question with a
 * single, testable answer.
 */
enum Permission: string
{
    case IncidentView = 'incident.view';
    case IncidentCreate = 'incident.create';
    case IncidentUpdate = 'incident.update';
    case IncidentTransition = 'incident.transition';
    case IncidentAssign = 'incident.assign';
    /** Commander-only levers: severity changes and handing over command. */
    case IncidentCommand = 'incident.command';
    case IncidentDelete = 'incident.delete';

    case CommentCreate = 'comment.create';
    case CommentModerate = 'comment.moderate';

    case UpdateCreate = 'update.create';

    case PostmortemView = 'postmortem.view';
    case PostmortemManage = 'postmortem.manage';
    case PostmortemPublish = 'postmortem.publish';

    case ServiceView = 'service.view';
    case ServiceManage = 'service.manage';

    case MemberView = 'member.view';
    case MemberManage = 'member.manage';

    case MetricsView = 'metrics.view';
    case AuditView = 'audit.view';
    case ExportRun = 'export.run';
    case OrganizationManage = 'organization.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
