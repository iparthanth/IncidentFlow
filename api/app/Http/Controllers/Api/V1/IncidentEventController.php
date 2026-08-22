<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentEventResource;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The incident timeline. Read-only — there is no store, update or destroy,
 * because the timeline is written exclusively by the domain service as a
 * consequence of real state changes. An endpoint that let a client append an
 * arbitrary event would make the timeline a claim rather than a record.
 *
 * Cursor pagination rather than offset: a timeline grows *while you are
 * reading it*, and `LIMIT/OFFSET` on a growing table silently repeats rows
 * across page boundaries. A cursor keyed on the (immutable) primary key cannot.
 */
final class IncidentEventController extends Controller
{
    public function index(Request $request, Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'direction' => ['sometimes', 'in:asc,desc'],
            // Lets the client fetch only what it missed after a stream gap.
            'after_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $query = $incident->events()
            ->getQuery()
            ->with('actor');

        if (($afterUlid = $validated['after_id'] ?? null) !== null) {
            $anchor = $incident->events()->getQuery()->where('ulid', $afterUlid)->value('id');
            if ($anchor !== null) {
                $query->where('id', '>', $anchor);
            }
        }

        $query->orderBy('id', $validated['direction'] ?? 'asc');

        return IncidentEventResource::collection(
            $query->cursorPaginate((int) ($validated['per_page'] ?? 50))->withQueryString(),
        );
    }
}
