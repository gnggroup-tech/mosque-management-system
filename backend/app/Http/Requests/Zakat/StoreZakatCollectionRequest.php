<?php

namespace App\Http\Requests\Zakat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZakatCollectionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('zakat.manage') === true; }
    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'faithful_id' => ['nullable', 'integer', 'exists:faithful,id'],
            'category' => ['required', Rule::in(['fitr', 'maal', 'agriculture', 'livestock', 'trade', 'gold_silver', 'other'])],
            'assessable_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'])],
            'collected_at' => ['required', 'date', 'before_or_equal:now'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'payer_name' => ['nullable', 'string', 'max:255', 'required_without_all:faithful_id,is_anonymous'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
