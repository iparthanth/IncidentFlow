<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssigneeRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class IncidentAssigneeController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function index(Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        return UserResource::collection($incident->assignees()->get());
    }

    public function store(Request $request, Incident $incident, Organization $organization): JsonResponse
    {
        Gate::authorize('assign', $incident);

        $validated = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('organization_members', 'user_id')
                    ->where('organization_id', $organization->getKey()),
            ],
            'role' => ['sometimes', Rule::in(AssigneeRole::values())],
        ], [
            'user_id.exists' => 'Responders must be members of this organization.',
        ]);

        /** @var User $assignee */
        $assignee = User::query()->findOrFail((int) $validated['user_id']);

        /**
         * A viewer cannot be paged. Assigning someone who lacks the standing to
         * act on the incident produces an assignment that looks like coverage
         * and provides none — which is worse than no assignment at all.
         */
        $role = $assignee->roleIn($organization);
        if ($role === null || ! $role->canBeAssigned()) {
            return response()->json([
                'error' => [
                    'code' => 'assignee_not_eligible',
                    'message' => 'That member\'s role does not allow them to be assigned as a responder.',
                    'details' => ['required_minimum_role' => 'responder'],
                    'request_id' => $request->headers->get('X-Request-Id'),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $actor */
        $actor = $request->user();

        $this->incidents->assign(
            $incident,
            $actor,
            $assignee,
            AssigneeRole::from((string) ($validated['role'] ?? AssigneeRole::Responder->value)),
        );

        return response()->json([
            'data' => UserResource::collection($incident->fresh()?->assignees()->get() ?? collect()),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Incident $incident, User $user): JsonResponse
    {
        Gate::authorize('assign', $incident);

        /** @var User $actor */
        $actor = $request->user();

        $this->incidents->unassign($incident, $actor, $user);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
