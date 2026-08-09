<?php

namespace App\Http\Requests\Donation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contributions.manage') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'faithful_id' => ['nullable', 'integer', 'exists:faithful,id'],
            'contribution_type' => ['required', Rule::in(['donation', 'offering', 'subscription', 'subsidy', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['sometimes', Rule::in(['GNF', 'USD', 'EUR'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'donor_name' => ['nullable', 'string', 'max:255', 'required_without_all:faithful_id,is_anonymous'],
            'donor_phone' => ['nullable', 'string', 'max:30'],
            'donor_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
