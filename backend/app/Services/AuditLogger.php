<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    public function log(string $event, ?Model $auditable = null, array $metadata = [], ?int $actorId = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actorId ?? $this->request->user()?->getKey(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'metadata' => $this->sanitize($metadata),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    private function sanitize(array $metadata): array
    {
        $sensitive = ['password', 'password_confirmation', 'remember_token', 'token'];

        return collect($metadata)->except($sensitive)->all();
    }
}
