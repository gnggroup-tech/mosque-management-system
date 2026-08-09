<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('announcements.manage') === true; }
    public function rules(): array
    {
        return [
            'mosque_id' => ['nullable', 'integer', 'exists:mosques,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'type' => ['required', Rule::in(['official', 'prayer', 'meeting', 'event', 'general'])],
            'priority' => ['sometimes', Rule::in(['normal', 'important', 'urgent'])],
            'audience' => ['required', Rule::in(['all', 'administrators', 'faithful'])],
            'visible_from' => ['nullable', 'date'],
            'visible_until' => ['nullable', 'date', 'after:visible_from'],
        ];
    }
}
