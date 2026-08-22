<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles are per-organization, not global: the same person can be an
 * administrator in one organization and a viewer in another. Authorization is
 * therefore always a function of (user, organization), which is why every
 * policy in this application starts by resolving the membership rather than
 * the user.
 */
enum OrganizationRole: string
{
    case Viewer = 'viewer';
    case Reporter = 'reporter';
    case Responder = 'responder';
    case IncidentCommander = 'incident_commander';
    case Administrator = 'administrator';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer',
            self::Reporter => 'Reporter',
            self::Responder => 'Responder',
            self::IncidentCommander => 'Incident Commander',
            self::Administrator => 'Administrator',
        };
    }

    /**
     * Permissions are cumulative by design — each role is the previous role
     * plus its own additions. Spelling the inheritance out here keeps the
     * privilege ladder visible in one screen.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $viewer = [
            Permission::IncidentView,
            Permission::PostmortemView,
            Permission::ServiceView,
            Permission::MemberView,
            Permission::MetricsView,
        ];

        $reporter = [
            ...$viewer,
            Permission::IncidentCreate,
            Permission::CommentCreate,
        ];

        $responder = [
            ...$reporter,
            Permission::IncidentUpdate,
            Permission::IncidentTransition,
            Permission::UpdateCreate,
        ];

        $commander = [
            ...$responder,
            Permission::IncidentAssign,
            Permission::IncidentCommand,
            Permission::CommentModerate,
            Permission::PostmortemManage,
            Permission::PostmortemPublish,
            Permission::ExportRun,
        ];

        return match ($this) {
            self::Viewer => $viewer,
            self::Reporter => $reporter,
            self::Responder => $responder,
            self::IncidentCommander => $commander,
            self::Administrator => [
                ...$commander,
                Permission::IncidentDelete,
                Permission::ServiceManage,
                Permission::MemberManage,
                Permission::AuditView,
                Permission::OrganizationManage,
            ],
        };
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    /** Seniority, used to stop a member from granting a role above their own. */
    public function rank(): int
    {
        return match ($this) {
            self::Viewer => 0,
            self::Reporter => 1,
            self::Responder => 2,
            self::IncidentCommander => 3,
            self::Administrator => 4,
        };
    }

    public function outranksOrEquals(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Roles eligible to be paged as responders on an incident. */
    public function canBeAssigned(): bool
    {
        return $this->rank() >= self::Responder->rank();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
