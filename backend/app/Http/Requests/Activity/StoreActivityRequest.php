<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activities.manage') === true;
    }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['prayer', 'sermon', 'course', 'religious_feast', 'meeting', 'cultural', 'social'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'registration_required' => ['sometimes', 'boolean'],
        ];
    }
}
