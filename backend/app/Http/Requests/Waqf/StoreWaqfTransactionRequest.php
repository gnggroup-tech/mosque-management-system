<?php

namespace App\Http\Requests\Waqf;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWaqfTransactionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('waqf.manage') === true; }
    public function rules(): array
    {
        $common = [
            'waqf_asset_id' => ['required', 'integer', 'exists:waqf_assets,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
        ];

        return $this->routeIs('admin.waqf.revenues.store')
            ? $common + [
                'source' => ['required', 'string', 'max:255'],
                'received_at' => ['required', 'date', 'before_or_equal:now'],
                'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'])],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]
            : $common + [
                'category' => ['required', Rule::in(['maintenance', 'repair', 'tax', 'management', 'beneficiary_support', 'other'])],
                'spent_at' => ['required', 'date', 'before_or_equal:now'],
                'purpose' => ['required', 'string', 'max:2000'],
                'supporting_document' => ['nullable', 'string', 'max:255'],
            ];
    }
}
