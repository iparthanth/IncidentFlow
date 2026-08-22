<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Assigns (or clears) the incident commander.
 *
 * PUT rather than POST: there is exactly one commander, and setting it twice
 * with the same value must be indistinguishable from setting it once.
 */
final class IncidentCommanderController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function __invoke(Request $request, Incident $incident, Organization $organization): IncidentResource
    {
        Gate::authorize('command', $incident);

        $validated = $request->validate([
            'commander_id' => [
                'present', 'nullable', 'integer',
                // Scoped to this tenant's membership table — an unscoped
                // `exists:users,id` would let any user id in the system be
                // installed as commander of this organization's incident.
                Rule::exists('organization_members', 'user_id')
                    ->where('organization_id', $organization->getKey()),
            ],
        ], [
            'commander_id.exists' => 'The commander must be a member of this organization.',
        ]);

        $commander = $validated['commander_id'] !== null
            ? User::query()->findOrFail((int) $validated['commander_id'])
            : null;

        /** @var User $actor */
        $actor = $request->user();

        $updated = $this->incidents->setCommander($incident, $actor, $commander);
        $updated->load(['service', 'reporter', 'commander', 'assignees']);

        return new IncidentResource($updated);
    }
}
