<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'unique:users,email'],
            /**
             * The policy itself lives in AppServiceProvider::configurePasswords()
             * so that one definition covers registration, password reset and any
             * future change-password endpoint — three places that must never
             * disagree about what counts as an acceptable password.
             */
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'organization_name' => ['required', 'string', 'min:2', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.uncompromised' => 'That password has appeared in a known data breach. Choose a different one.',
            'organization_name.required' => 'Name the organization this account will manage incidents for.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            // Case-insensitive uniqueness in practice, without relying on a
            // citext column or a functional index the SQLite tests would skip.
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
