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
}
