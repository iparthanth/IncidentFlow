<?php

declare(strict_types=1);

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters are input too.
 *
 * Validating them is not pedantry — an unvalidated `sort` is a column-name
 * injection into an ORDER BY, and an unbounded `per_page` is a denial of
 * service dressed up as pagination. Whitelisting both turns a hostile query
 * string into a 422 instead of an outage.
 */
final class IndexIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes via the IncidentPolicy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array', 'max:5'],
            'status.*' => [Rule::in(IncidentStatus::values())],

            'severity' => ['sometimes', 'array', 'max:4'],
            'severity.*' => [Rule::in(IncidentSeverity::values())],

            'service_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'commander_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'q' => ['sometimes', 'nullable', 'string', 'max:200'],

            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],

            'sort' => ['sometimes', Rule::in(Incident::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.config('incidents.pagination.max_per_page'),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],

            'active_only' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sort.in' => 'Incidents can only be sorted by: '.implode(', ', Incident::SORTABLE).'.',
            'per_page.max' => 'At most '.config('incidents.pagination.max_per_page').' incidents may be requested per page.',
        ];
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_values(array_map('strval', (array) $this->validated('status', [])));
    }

    /** @return list<string> */
    public function severities(): array
    {
        return array_values(array_map('strval', (array) $this->validated('severity', [])));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('incidents.pagination.default_per_page'));
    }

    public function sortColumn(): string
    {
        return (string) $this->validated('sort', 'created_at');
    }

    public function sortDirection(): string
    {
        return (string) $this->validated('direction', 'desc');
    }
}
