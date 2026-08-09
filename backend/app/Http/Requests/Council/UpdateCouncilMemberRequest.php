<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouncilMemberRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('council-members.update') === true; }

    public function rules(): array
    {
        $start = $this->input('started_at', $this->route('member')?->started_at?->toDateString());
        return [
            'function' => ['sometimes', 'required', Rule::in(['president', 'vice_president', 'imam', 'muezzin', 'secretary', 'treasurer', 'advisor', 'other'])],
            'title' => ['nullable', 'string', 'max:100'],
            'responsibilities' => ['nullable', 'string', 'max:5000'],
            'started_at' => ['sometimes', 'required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:'.$start],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'ended'])],
        ];
    }
}
