<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubsidyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finances.manage') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'source' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'supporting_document' => ['nullable', 'string', 'max:255'],
        ];
    }
}
