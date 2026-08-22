<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * The privilege ladder.
 *
 * The property worth pinning down is *monotonicity*: every role must hold a
 * superset of the one below it. A refactor that accidentally drops a
 * permission from a senior role produces a subtle, hard-to-notice regression —
 * an incident commander who can no longer comment — that no individual
 * endpoint test would catch.
 */
final class OrganizationRoleTest extends TestCase
{
    public function test_each_role_holds_a_superset_of_the_role_below(): void
    {
        $ordered = [
            OrganizationRole::Viewer,
            OrganizationRole::Reporter,
            OrganizationRole::Responder,
            OrganizationRole::IncidentCommander,
            OrganizationRole::Administrator,
        ];

        for ($i = 1; $i < count($ordered); $i++) {
            $lower = $ordered[$i - 1]->permissions();
            $higher = $ordered[$i]->permissions();

            foreach ($lower as $permission) {
                $this->assertContains(
                    $permission,
                    $higher,
                    sprintf(
                        '%s should inherit %s from %s',
                        $ordered[$i]->value,
                        $permission->value,
                        $ordered[$i - 1]->value,
                    ),
                );
            }
        }
    }

    public function test_a_viewer_can_only_read(): void
    {
        $viewer = OrganizationRole::Viewer;

        $this->assertTrue($viewer->has(Permission::IncidentView));
        $this->assertTrue($viewer->has(Permission::MetricsView));

        foreach ([
            Permission::IncidentCreate,
            Permission::IncidentTransition,
            Permission::CommentCreate,
            Permission::ServiceManage,
            Permission::AuditView,
        ] as $forbidden) {
            $this->assertFalse($viewer->has($forbidden), "Viewer must not hold {$forbidden->value}");
        }
    }

    public function test_a_reporter_can_open_an_incident_but_not_drive_it(): void
    {
        $reporter = OrganizationRole::Reporter;

        $this->assertTrue($reporter->has(Permission::IncidentCreate));
        $this->assertTrue($reporter->has(Permission::CommentCreate));
        $this->assertFalse($reporter->has(Permission::IncidentTransition));
        $this->assertFalse($reporter->has(Permission::IncidentAssign));
    }

    public function test_a_responder_can_drive_an_incident_but_not_reassign_it(): void
    {
        $responder = OrganizationRole::Responder;

        $this->assertTrue($responder->has(Permission::IncidentTransition));
        $this->assertTrue($responder->has(Permission::UpdateCreate));
        $this->assertFalse($responder->has(Permission::IncidentAssign));
        $this->assertFalse($responder->has(Permission::IncidentCommand));
    }

    public function test_only_administrators_hold_the_tenant_level_permissions(): void
    {
        foreach ([Permission::ServiceManage, Permission::MemberManage, Permission::AuditView, Permission::IncidentDelete] as $permission) {
            foreach (OrganizationRole::cases() as $role) {
                $expected = $role === OrganizationRole::Administrator;

                $this->assertSame(
                    $expected,
                    $role->has($permission),
                    sprintf('%s holding %s should be %s', $role->value, $permission->value, var_export($expected, true)),
                );
            }
        }
    }

    public function test_only_responders_and_above_can_be_assigned(): void
    {
        $this->assertFalse(OrganizationRole::Viewer->canBeAssigned());
        $this->assertFalse(OrganizationRole::Reporter->canBeAssigned());
        $this->assertTrue(OrganizationRole::Responder->canBeAssigned());
        $this->assertTrue(OrganizationRole::IncidentCommander->canBeAssigned());
        $this->assertTrue(OrganizationRole::Administrator->canBeAssigned());
    }

    public function test_rank_ordering_prevents_privilege_escalation(): void
    {
        $this->assertTrue(OrganizationRole::Administrator->outranksOrEquals(OrganizationRole::IncidentCommander));
        $this->assertTrue(OrganizationRole::Responder->outranksOrEquals(OrganizationRole::Responder));
        $this->assertFalse(OrganizationRole::Responder->outranksOrEquals(OrganizationRole::Administrator));
        $this->assertFalse(OrganizationRole::IncidentCommander->outranksOrEquals(OrganizationRole::Administrator));
    }

    public function test_every_permission_is_reachable_by_some_role(): void
    {
        // A permission nothing grants is dead weight that reads like a control.
        $granted = [];
        foreach (OrganizationRole::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $granted[$permission->value] = true;
            }
        }

        foreach (Permission::cases() as $permission) {
            $this->assertArrayHasKey(
                $permission->value,
                $granted,
                "{$permission->value} is defined but granted to no role",
            );
        }
    }
}
