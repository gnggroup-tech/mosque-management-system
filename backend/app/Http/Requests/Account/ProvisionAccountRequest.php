<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvisionAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'primary_mosque_ids' => $this->input('primary_mosque_ids', []),
            'primary_replacements' => $this->input('primary_replacements', []),
        ]);

        if ($this->has('membership_ids') || $this->has('membership_types')) {
            $types = $this->input('membership_types', []);
            $memberships = collect($this->input('membership_ids', []))
                ->map(fn ($id): array => [
                    'mosque_id' => (int) $id,
                    'membership_type' => $types[$id] ?? null,
                ])
                ->values()
                ->all();
            $this->merge(['memberships' => $memberships]);
        }
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'user'])],
            'memberships' => ['present', 'array'],
            'memberships.*.mosque_id' => ['required', 'integer', 'distinct', Rule::exists('mosques', 'id')->whereNull('deleted_at')],
            'memberships.*.membership_type' => ['required', Rule::in(['administrator', 'member'])],
            'primary_mosque_ids' => ['present', 'array'],
            'primary_mosque_ids.*' => ['integer', 'distinct', Rule::exists('mosques', 'id')->whereNull('deleted_at')],
            'primary_replacements' => ['present', 'array'],
            'primary_replacements.*' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'version' => ['required', 'string', 'size:64'],
            'status' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'permissions' => ['prohibited'],
        ];
    }
}
