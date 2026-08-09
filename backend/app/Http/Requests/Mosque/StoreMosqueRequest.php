<?php

namespace App\Http\Requests\Mosque;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMosqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('mosques.create') === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:mosques,code'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'region' => ['required', 'string', 'max:100'],
            'prefecture' => ['required', 'string', 'max:100'],
            'commune' => ['required', 'string', 'max:100'],
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
}
