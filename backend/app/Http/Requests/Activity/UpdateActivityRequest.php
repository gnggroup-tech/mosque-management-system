<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('activities.manage') === true; }

    public function rules(): array
    {
        return [
            'mosque_id' => ['sometimes', 'integer', 'exists:mosques,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['prayer', 'sermon', 'course', 'religious_feast', 'meeting', 'cultural', 'social'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'registration_required' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $activity = $this->route('activity');
            $start = $this->date('starts_at') ?? $activity?->starts_at;
            $end = $this->date('ends_at') ?? $activity?->ends_at;
            if ($start && $end && $end->lessThanOrEqualTo($start)) {
                $validator->errors()->add('ends_at', 'La fin doit être postérieure au début.');
            }
        }];
    }
}
