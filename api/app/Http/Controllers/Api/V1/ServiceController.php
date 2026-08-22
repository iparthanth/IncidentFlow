<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ServiceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Service::class);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'active_only' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $query = Service::query()
            ->forOrganization($organization)
            // Counts only the incidents that matter on a service list: the
            // ones currently burning.
            ->withCount(['incidents' => fn ($q) => $q->activeOnly()]);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if (($term = $validated['q'] ?? null) !== null && $term !== '') {
            $query->whereLike('name', '%'.$term.'%', caseSensitive: false);
        }

        return ServiceResource::collection(
            $query->orderBy('tier')->orderBy('name')->paginate((int) ($validated['per_page'] ?? 50))->withQueryString(),
        );
    }

    public function show(Service $service): ServiceResource
    {
        Gate::authorize('view', $service);

        return new ServiceResource($service->loadCount(['incidents' => fn ($q) => $q->activeOnly()]));
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', Service::class);

        $validated = $request->validate($this->rules($organization));

        /** @var User $user */
        $user = $request->user();

        $service = Service::query()->create([
            ...$validated,
            'organization_id' => $organization->getKey(),
        ]);

        $this->audit->record('service.created', $service, $user, $organization->getKey(), [
            'after' => $service->only(['name', 'slug', 'tier', 'is_active']),
        ]);

        return (new ServiceResource($service))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, Service $service, Organization $organization): ServiceResource
    {
        Gate::authorize('update', $service);

        $validated = $request->validate($this->rules($organization, $service));

        /** @var User $user */
        $user = $request->user();

        $service->fill($validated);

        if ($service->isDirty()) {
            $service->save();
            $this->audit->recordModelUpdate('service.updated', $service, $user, $organization->getKey());
        }

        return new ServiceResource($service);
    }

    /**
     * Soft delete. Incidents keep their `service_id` (the FK is nullOnDelete
     * only for a hard delete), so history stays readable — a service being
     * retired must not rewrite what broke last quarter.
     */
    public function destroy(Request $request, Service $service): JsonResponse
    {
        Gate::authorize('delete', $service);

        /** @var User $user */
        $user = $request->user();

        $this->audit->record('service.deleted', $service, $user, $service->organization_id, [
            'before' => $service->only(['name', 'slug', 'tier']),
        ]);

        $service->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function rules(Organization $organization, ?Service $existing = null): array
    {
        $required = $existing === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'owner_team' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tier' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'is_active' => ['sometimes', 'boolean'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:128', 'alpha_dash',
                // Unique per tenant, not globally: two customers may both run
                // a service called "checkout".
                Rule::unique('services', 'slug')
                    ->where('organization_id', $organization->getKey())
                    ->ignore($existing?->getKey()),
            ],
        ];
    }
}
