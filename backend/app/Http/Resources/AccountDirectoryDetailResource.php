<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AccountDirectoryDetailResource extends AccountDirectoryResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
