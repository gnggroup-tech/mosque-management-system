<?php

namespace App\Http\Requests\Mosque;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMosqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('mosques.update') === true;
    }

    public function rules(): array
    {
        $mosque = $this->route('mosque');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:30', 'alpha_dash', Rule::unique('mosques', 'code')->ignore($mosque)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'region' => ['sometimes', 'required', 'string', 'max:100'],
            'prefecture' => ['sometimes', 'required', 'string', 'max:100'],
            'commune' => ['sometimes', 'required', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'infrastructures' => ['nullable', 'array'],
            'infrastructures.*' => ['string', 'max:255'],
            'admin_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => __('This field is required.'),
            'string' => __('This field must be text.'),
            'max' => __('This field is too long.'),
            'email' => __('Enter a valid email address.'),
            'numeric' => __('Enter a valid number.'),
            'between' => __('The value is outside the permitted range.'),
            'in' => __('Choose one of the permitted values.'),
            'integer' => __('Choose a valid administrator.'),
            'exists' => __('The selected administrator is not available.'),
            'array' => __('The infrastructure list is invalid.'),
            'alpha_dash' => __('Use only letters, numbers, dashes and underscores.'),
            'unique' => __('This mosque code is already in use.'),
        ];
    }
}
