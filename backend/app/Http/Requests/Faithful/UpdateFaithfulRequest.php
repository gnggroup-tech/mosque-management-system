<?php

namespace App\Http\Requests\Faithful;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFaithfulRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('faithful.manage') === true;
    }

    public function rules(): array
    {
        $faithful = $this->route('faithful');

        return [
            'mosque_id' => ['sometimes', 'required', 'integer', Rule::exists('mosques', 'id')->whereNull('deleted_at')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('faithful', 'user_id')->ignore($faithful)],
            'registration_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('faithful', 'registration_number')->ignore($faithful)],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'joined_at' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'deceased'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'consent_at' => ['sometimes', 'required', 'date', 'before_or_equal:now'],
        ];
    }
}
