<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finances.manage') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'category' => ['required', Rule::in(['utilities', 'maintenance', 'salary', 'education', 'social', 'administration', 'equipment', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'spent_at' => ['required', 'date', 'before_or_equal:now'],
            'purpose' => ['required', 'string', 'max:2000'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'supporting_document' => ['required', 'string', 'max:255'],
        ];
    }
}
