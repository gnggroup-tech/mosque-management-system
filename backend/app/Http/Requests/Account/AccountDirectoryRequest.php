<?php

namespace App\Http\Requests\Account;

use App\Enums\AccountStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'role' => ['nullable', Rule::in(array_keys(config('permissions.roles')))],
            'search' => [
                'nullable',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (str_contains($value, '%') || str_contains($value, '_') || str_contains($value, '\\')) {
                        $fail('The search field contains unsupported wildcard characters.');

                        return;
                    }

                    if (! ctype_digit($value) && mb_strlen($value) < 2) {
                        $fail('The search field must be at least 2 characters when it is not an account ID.');
                    }
                },
            ],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', Rule::in(['id', 'name', 'status', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => Str::squish($this->input('search'))]);
        }
    }
}
