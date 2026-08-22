<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class OrganizationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The organizations the caller belongs to.
     *
     * Deliberately outside the `organization` middleware: this is the call a
     * client makes *because* it does not yet know which tenant to act in, so
     * requiring a tenant header would be circular.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return OrganizationResource::collection(
            $user->organizations()->orderBy('name')->get(),
        );
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone', 'max:64'],
            'settings' => ['sometimes', 'array'],
            // Per-tenant SLA overrides, bounded to sane values: a target of 0
            // would make every incident breach, and 10080 (a week) would make
            // the metric meaningless.
            'settings.acknowledgement_targets' => ['sometimes', 'array'],
            'settings.acknowledgement_targets.*' => ['integer', 'min:1', 'max:10080'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $organization->fill($validated);

        if ($organization->isDirty()) {
            $organization->save();
            $this->audit->recordModelUpdate('organization.updated', $organization, $user, $organization->getKey());
        }

        return new OrganizationResource($organization);
    }
}
