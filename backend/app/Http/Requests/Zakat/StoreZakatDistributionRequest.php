<?php

namespace App\Http\Requests\Zakat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZakatDistributionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('zakat.manage') === true; }
    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'zakat_beneficiary_id' => ['required', 'integer', 'exists:zakat_beneficiaries,id'],
            'category' => ['required', Rule::in(['fitr', 'maal', 'agriculture', 'livestock', 'trade', 'gold_silver', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'])],
            'distributed_at' => ['required', 'date', 'before_or_equal:now'],
            'purpose' => ['required', 'string', 'max:2000'],
            'supporting_document' => ['nullable', 'string', 'max:255'],
        ];
    }
}
