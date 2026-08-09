<?php

namespace App\Observers;

use App\Models\Mosque;
use App\Services\AuditLogger;

class MosqueObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function created(Mosque $mosque): void
    {
        $this->auditLogger->log('mosque.created', $mosque, ['code' => $mosque->code, 'name' => $mosque->name]);
    }

    public function updated(Mosque $mosque): void
    {
        $changes = collect($mosque->getChanges())->except(['updated_at'])->all();

        if ($changes !== []) {
            $this->auditLogger->log('mosque.updated', $mosque, ['changes' => $changes]);
        }
    }

    public function deleted(Mosque $mosque): void
    {
        $this->auditLogger->log('mosque.deleted', $mosque, ['code' => $mosque->code, 'name' => $mosque->name]);
    }
}
