<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentCommentResource;
use App\Models\Incident;
use App\Models\IncidentComment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class IncidentCommentController extends Controller
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return IncidentCommentResource::collection(
            $incident->comments()
                ->getQuery()
                // `incident` is loaded because the resource asks the policy
                // whether a delete control should render, and the policy reads
                // the incident's organization. Without it, strict mode raises a
                // lazy-load violation — which is the point of strict mode.
                ->with(['author', 'incident'])
                ->orderByDesc('id')
                ->paginate((int) ($validated['per_page'] ?? 25))
                ->withQueryString(),
        );
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('comment', $incident);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $comment = $this->incidents->comment($incident, $user, (string) $validated['body']);
        $comment->load(['author', 'incident']);

        return (new IncidentCommentResource($comment))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, IncidentComment $comment): JsonResponse
    {
        $comment->loadMissing('incident');

        Gate::authorize('deleteComment', $comment);

        /** @var User $user */
        $user = $request->user();

        $this->audit->record(
            'comment.deleted',
            $comment,
            $user,
            $comment->incident?->organization_id,
            ['before' => ['body' => $comment->body]],
        );

        $comment->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
