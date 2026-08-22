<?php

declare(strict_types=1);

namespace App\Http\Requests\Incidents;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Descriptive edits only.
 *
 * Severity and status are deliberately absent: they are transitions with
 * notification and metric consequences, and letting them ride along in a
 * generic PATCH would route them around the state machine.
 */
final class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = app(Organization::class)->getKey();

        return [
            'title' => ['sometimes', 'string', 'min:5', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'service_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('services', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'detected_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
