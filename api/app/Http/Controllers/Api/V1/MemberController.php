<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class MemberController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TokenService $tokens,
    ) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $organization);

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(OrganizationRole::values())],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'assignable_only' => ['sometimes', 'boolean'],
        ]);

        $query = OrganizationMember::query()
            ->forOrganization($organization)
            ->with('user');

        if (isset($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        // Powers the responder picker: only roles that can actually be paged.
        if ($request->boolean('assignable_only')) {
            $query->whereIn('role', array_values(array_map(
                static fn (OrganizationRole $role): string => $role->value,
                array_filter(OrganizationRole::cases(), static fn (OrganizationRole $r): bool => $r->canBeAssigned()),
            )));
        }

        if (($term = $validated['q'] ?? null) !== null && $term !== '') {
            $query->whereHas('user', fn ($q) => $q
                ->whereLike('name', '%'.$term.'%', caseSensitive: false)
                ->orWhereLike('email', '%'.$term.'%', caseSensitive: false));
        }

        return MemberResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 50))->withQueryString(),
        );
    }

    /**
     * Adds an existing account to this organization.
     *
     * Not an invitation flow — it assumes the account exists — but the same
     * escalation guard applies: the actor cannot grant a role above their own.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(OrganizationRole::values())],
        ]);

        $targetRole = OrganizationRole::from((string) $validated['role']);

        /** @var User $actor */
        $actor = $request->user();
        $actorRole = $actor->roleIn($organization);

        if ($actorRole === null || ! $actorRole->outranksOrEquals($targetRole)) {
            return $this->forbidden($request, 'You cannot grant a role above your own.');
        }

        /** @var User|null $user */
        $user = User::query()->where('email', mb_strtolower(trim((string) $validated['email'])))->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'No account exists with that email address.',
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->belongsToOrganization($organization)) {
            return response()->json([
                'error' => [
                    'code' => 'already_a_member',
                    'message' => 'That account is already a member of this organization.',
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $member = DB::transaction(function () use ($organization, $user, $targetRole, $actor): OrganizationMember {
            $member = OrganizationMember::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => $targetRole,
                'invited_by' => $actor->getKey(),
                'joined_at' => now(),
            ]);

            $this->audit->record('member.added', $member, $actor, $organization->getKey(), [
                'after' => ['user_id' => $user->getKey(), 'role' => $targetRole->value],
            ]);

            return $member;
        });

        return (new MemberResource($member->load('user')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, OrganizationMember $member, Organization $organization): MemberResource
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(OrganizationRole::values())],
        ]);

        $targetRole = OrganizationRole::from((string) $validated['role']);

        // The policy holds the full escalation rule: tenant match, permission,
        // no self-modification, and no granting above your own rank.
        Gate::authorize('assignRole', [$member, $targetRole]);

        /** @var User $actor */
        $actor = $request->user();

        if ($member->role !== $targetRole) {
            DB::transaction(function () use ($member, $targetRole, $actor, $organization): void {
                $member->role = $targetRole;
                $member->save();

                $this->audit->recordModelUpdate('member.role_changed', $member, $actor, $organization->getKey());
            });

            /**
             * Authorization is read from the database on every request, so the
             * new role is already in force. Refresh tokens are revoked anyway:
             * a demotion should end the affected person's long-lived sessions
             * rather than let a stale device keep silently renewing.
             */
            if ($member->user !== null) {
                $this->tokens->revokeAllForUser($member->user, 'role_changed');
            }
        }

        return new MemberResource($member->load('user'));
    }

    public function destroy(Request $request, OrganizationMember $member, Organization $organization): JsonResponse
    {
        Gate::authorize('removeMember', $member);

        /** @var User $actor */
        $actor = $request->user();

        /**
         * Never leave a tenant unadministered. Removing the last administrator
         * would produce an organization nobody can manage members, services or
         * settings for — recoverable only by a database edit.
         */
        if ($member->role === OrganizationRole::Administrator) {
            $remaining = OrganizationMember::query()
                ->forOrganization($organization)
                ->where('role', OrganizationRole::Administrator->value)
                ->where('id', '!=', $member->getKey())
                ->count();

            if ($remaining === 0) {
                return $this->forbidden($request, 'An organization must keep at least one administrator.');
            }
        }

        DB::transaction(function () use ($member, $actor, $organization): void {
            $this->audit->record('member.removed', $member, $actor, $organization->getKey(), [
                'before' => ['user_id' => $member->user_id, 'role' => $member->role->value],
            ]);

            $member->delete();
        });

        if ($member->user !== null) {
            $this->tokens->revokeAllForUser($member->user, 'membership_removed');
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function forbidden(Request $request, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => $message,
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
