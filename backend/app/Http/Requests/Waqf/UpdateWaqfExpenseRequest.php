<?php

namespace App\Http\Requests\Waqf;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWaqfExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('waqf.manage') === true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'category' => ['sometimes', Rule::in(['maintenance', 'repair', 'tax', 'management', 'beneficiary_support', 'other'])],
            'spent_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'purpose' => ['sometimes', 'string', 'max:2000'],
            'supporting_document' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
