<?php

declare(strict_types=1);

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(IncidentStatus::values())],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Whether the accompanying note is safe to show on a status page.
            'public' => ['sometimes', 'boolean'],
        ];
    }

    public function targetStatus(): IncidentStatus
    {
        return IncidentStatus::from((string) $this->validated('status'));
    }
}
