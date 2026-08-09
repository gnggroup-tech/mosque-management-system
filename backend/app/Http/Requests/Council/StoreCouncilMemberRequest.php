<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouncilMemberRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('council-members.create') === true; }

    public function rules(): array
    {
        return [
            'mosque_council_id' => ['required', 'integer', Rule::exists('mosque_councils', 'id')->whereNull('deleted_at')],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'function' => ['required', Rule::in(['president', 'vice_president', 'imam', 'muezzin', 'secretary', 'treasurer', 'advisor', 'other'])],
            'title' => ['nullable', 'string', 'max:100'],
            'responsibilities' => ['nullable', 'string', 'max:5000'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'ended'])],
        ];
    }
}
