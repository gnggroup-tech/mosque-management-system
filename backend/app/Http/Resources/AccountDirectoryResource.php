<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'status' => $this->status->value,
            'locale' => $this->locale,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'roles' => $this->roles->pluck('name')->values()->all(),
            'administered_mosques' => $this->administeredMosques
                ->map(fn ($mosque): array => [
                    'id' => $mosque->getKey(),
                    'name' => $mosque->name,
                ])
                ->values()
                ->all(),
        ];
    }
}
