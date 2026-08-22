<?php

declare(strict_types=1);

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentSeverity;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorized in the controller against the IncidentPolicy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(Organization::class)->getKey();

        return [
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(IncidentSeverity::values())],

            /**
             * Scoped existence checks, not bare `exists:services,id`.
             *
             * An unscoped rule would happily accept another tenant's service id
             * and silently link this incident to it — the same
             * broken-object-level-authorization class of bug that policies
             * guard against, arriving through validation instead.
             */
            'service_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('services', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'commander_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('organization_members', 'user_id')->where('organization_id', $organizationId),
            ],
            'assignee_ids' => ['sometimes', 'array', 'max:20'],
            'assignee_ids.*' => [
                'integer',
                Rule::exists('organization_members', 'user_id')->where('organization_id', $organizationId),
            ],

            // Detection can precede reporting, never follow it.
            'detected_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'source' => ['sometimes', 'string', Rule::in(['web', 'api', 'integration', 'monitoring'])],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_id.exists' => 'That service does not exist in this organization.',
            'commander_id.exists' => 'The incident commander must be a member of this organization.',
            'assignee_ids.*.exists' => 'Responders must be members of this organization.',
            'detected_at.before_or_equal' => 'An incident cannot have been detected in the future.',
        ];
    }
}
