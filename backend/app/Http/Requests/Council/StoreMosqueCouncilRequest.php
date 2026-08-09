<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMosqueCouncilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('councils.create') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', Rule::exists('mosques', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'mandate_start' => ['required', 'date'],
            'mandate_end' => ['required', 'date', 'after:mandate_start'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'dissolved', 'expired'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
