<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouncilDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('council-meetings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'],
            'outcome' => ['required', Rule::in(['approved', 'rejected', 'deferred'])],
            'votes_for' => ['required', 'integer', 'min:0'], 'votes_against' => ['required', 'integer', 'min:0'],
            'abstentions' => ['required', 'integer', 'min:0'], 'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
