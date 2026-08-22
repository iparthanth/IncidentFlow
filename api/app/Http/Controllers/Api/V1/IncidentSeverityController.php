<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentSeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Severity changes — commander-and-above, because escalating to SEV-1 pages
 * the whole on-call population and de-escalating quietly makes an outage look
 * smaller than it was.
 */
final class IncidentSeverityController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function __invoke(Request $request, Incident $incident): IncidentResource
    {
        Gate::authorize('command', $incident);

        $validated = $request->validate([
            'severity' => ['required', Rule::in(IncidentSeverity::values())],
            // Not optional when de-escalating: see below.
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $target = IncidentSeverity::from((string) $validated['severity']);

        /**
         * Downgrades must be justified. Escalation is self-explanatory —
         * something got worse — but quietly moving a SEV-1 to SEV-3 changes
         * what the postmortem policy requires and how the incident reads in
         * every report afterwards, so it needs a sentence on the record.
         */
        if ($target->weight() > $incident->severity->weight()) {
            $request->validate([
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
            ], [
                'reason.required' => 'Lowering severity requires a reason for the record.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $updated = $this->incidents->changeSeverity($incident, $user, $target, $validated['reason'] ?? null);
        $updated->load(['service', 'reporter', 'commander', 'assignees']);

        return new IncidentResource($updated);
    }
}
