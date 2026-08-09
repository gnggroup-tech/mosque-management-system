<?php

namespace App\Http\Requests\Announcement;

class UpdateAnnouncementRequest extends StoreAnnouncementRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as &$rule) {
            if (($key = array_search('required', $rule, true)) !== false) {
                $rule[$key] = 'sometimes';
            }
        }

        return $rules;
    }
}
