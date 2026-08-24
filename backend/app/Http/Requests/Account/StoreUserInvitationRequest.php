<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => str($this->input('name', ''))->squish()->toString(),
            'email' => str($this->input('email', ''))->trim()->lower()->toString(),
            'locale' => str($this->input('locale', ''))->trim()->lower()->toString(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'locale' => ['required', Rule::in(config('app.supported_locales', ['fr', 'en', 'ar']))],
            'status' => ['prohibited'],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'permission' => ['prohibited'],
            'permissions' => ['prohibited'],
            'mosque_id' => ['prohibited'],
        ];
    }
}
