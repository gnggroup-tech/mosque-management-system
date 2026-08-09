<?php

namespace App\Http\Requests\Council;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouncilMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('council-meetings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'mosque_council_id' => ['required', 'integer', 'exists:mosque_councils,id'],
            'title' => ['required', 'string', 'max:255'], 'agenda' => ['required', 'string', 'max:10000'],
            'scheduled_at' => ['required', 'date'], 'location' => ['nullable', 'string', 'max:255'],
            'quorum_required' => ['required', 'integer', 'min:1', 'max:500'],
            'participant_ids' => ['required', 'array', 'min:1'], 'participant_ids.*' => ['integer', 'distinct', 'exists:council_members,id'],
        ];
    }
}
