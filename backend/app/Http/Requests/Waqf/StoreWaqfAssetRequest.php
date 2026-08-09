<?php

namespace App\Http\Requests\Waqf;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWaqfAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('waqf.manage') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['land', 'building', 'shop', 'farm', 'cash', 'equipment', 'other'])],
            'description' => ['nullable', 'string', 'max:3000'],
            'address' => ['nullable', 'string', 'max:500'],
            'estimated_value' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'dedicated_at' => ['required', 'date', 'before_or_equal:today'],
            'deed_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
