<?php

namespace App\Http\Requests\Faithful;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFaithfulRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('faithful.manage') === true; }

    public function rules(): array
    {
        return [
            'mosque_id' => ['required', 'integer', Rule::exists('mosques', 'id')->whereNull('deleted_at')],
            'user_id' => ['nullable', 'integer', 'unique:faithful,user_id', Rule::exists('users', 'id')],
            'registration_number' => ['required', 'string', 'max:50', 'unique:faithful,registration_number'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'joined_at' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'deceased'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'consent_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
