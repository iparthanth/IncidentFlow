<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentSeverity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\IndexIncidentsRequest;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class IncidentController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    /**
     * Paginated, filtered, sorted incident list.
     *
     * Every relation the resource can render is eager-loaded here. With
     * `Model::preventLazyLoading` on in development, forgetting one is a test
     * failure rather than a page that quietly fires 25 extra queries — which
     * is exactly the sort of thing that only becomes visible under the load of
     * a real incident, when the dashboard is least able to afford it.
     */
    public function index(IndexIncidentsRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Incident::class);

        $query = Incident::query()
            ->forOrganization($organization)
            ->with(['service', 'reporter', 'commander', 'assignees'])
            ->withCount(['comments', 'updates', 'events'])
            ->whereStatusIn($request->statuses())
            ->whereSeverityIn($request->severities());

        if ($request->boolean('active_only')) {
            $query->activeOnly();
        }

        if (($serviceId = $request->validated('service_id')) !== null) {
            $query->where('service_id', (int) $serviceId);
        }

        if (($assigneeId = $request->validated('assignee_id')) !== null) {
            $query->assignedTo((int) $assigneeId);
        }

        if (($commanderId = $request->validated('commander_id')) !== null) {
            $query->where('commander_id', (int) $commanderId);
        }

        if (($term = $request->validated('q')) !== null && $term !== '') {
            $query->search((string) $term);
        }

        if (($from = $request->validated('from')) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $request->validated('to')) !== null) {
            $query->where('created_at', '<=', $to);
        }

        // `id` as the tiebreaker keeps pagination stable: without it, rows
        // sharing a created_at can reshuffle between pages and a user sees the
        // same incident twice while another never appears.
        $query->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', 'desc');

        return IncidentResource::collection(
            $query->paginate($request->perPage())->appends($request->query()),
        );
    }

    public function store(StoreIncidentRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', Incident::class);

        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $incident = $this->incidents->create($organization, $user, [
            ...$data,
            'severity' => IncidentSeverity::from((string) $data['severity']),
        ]);

        $incident->load(['service', 'reporter', 'commander', 'assignees']);

        return (new IncidentResource($incident))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('incidents.show', ['incident' => $incident->getKey()], absolute: false));
    }

    public function show(Incident $incident): IncidentResource
    {
        Gate::authorize('view', $incident);

        $incident->load([
            'service',
            'reporter',
            'commander',
            'assignees',
            'postmortem',
            'updates.author',
        ])->loadCount(['comments', 'updates', 'events']);

        return new IncidentResource($incident);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('update', $incident);

        /** @var User $user */
        $user = $request->user();

        $updated = $this->incidents->update($incident, $user, $request->validated());
        $updated->load(['service', 'reporter', 'commander', 'assignees']);

        return new IncidentResource($updated);
    }

    /**
     * Soft delete. The timeline, audit trail and postmortem all survive —
     * "delete" here means "remove from the working list", never "erase the
     * record that this happened".
     */
    public function destroy(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('delete', $incident);

        /** @var User $user */
        $user = $request->user();

        $this->incidents->delete($incident, $user);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
