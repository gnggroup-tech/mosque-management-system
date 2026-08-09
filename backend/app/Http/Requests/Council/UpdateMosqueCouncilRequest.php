<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMosqueCouncilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('councils.update') === true;
    }

    public function rules(): array
    {
        $start = $this->input('mandate_start', $this->route('council')?->mandate_start?->toDateString());

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'mandate_start' => ['sometimes', 'required', 'date'],
            'mandate_end' => ['sometimes', 'required', 'date', 'after:'.$start],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'dissolved', 'expired'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
