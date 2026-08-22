<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\TransitionIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Support\Facades\Gate;

/**
 * Status transitions.
 *
 * A separate endpoint rather than a field on PATCH /incidents/{id}, because a
 * status change is not an attribute write: it validates against a state
 * machine, stamps the clocks that MTTA and MTTR are computed from, appends a
 * timeline event and pages people. Hiding all of that inside a generic update
 * makes it far too easy for a future endpoint to set `status` directly and
 * bypass every one of those steps.
 */
final class IncidentStatusController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function __invoke(TransitionIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('transition', $incident);

        /** @var User $user */
        $user = $request->user();

        $updated = $this->incidents->transition(
            $incident,
            $user,
            $request->targetStatus(),
            $request->validated('note'),
            $request->boolean('public'),
        );

        $updated->load(['service', 'reporter', 'commander', 'assignees']);

        return new IncidentResource($updated);
    }
}
