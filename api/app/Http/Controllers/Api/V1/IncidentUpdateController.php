<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class IncidentUpdateController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function index(Request $request, Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'public_only' => ['sometimes', 'boolean'],
        ]);

        $query = $incident->updates()->getQuery()->with('author');

        if ($request->boolean('public_only')) {
            $query->where('is_public', true);
        }

        return IncidentUpdateResource::collection(
            $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25))->withQueryString(),
        );
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('postUpdate', $incident);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'public' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $update = $this->incidents->postUpdate(
            $incident,
            $user,
            (string) $validated['message'],
            (bool) ($validated['public'] ?? false),
        );

        $update->load('author');

        return (new IncidentUpdateResource($update))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
