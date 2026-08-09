<?php

namespace App\Observers;

use App\Models\MosqueCouncil;
use App\Services\AuditLogger;

class MosqueCouncilObserver
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function created(MosqueCouncil $council): void
    {
        $this->auditLogger->log('council.created', $council, [
            'mosque_id' => $council->mosque_id,
            'name' => $council->name,
        ]);
    }

    public function updated(MosqueCouncil $council): void
    {
        $changes = collect($council->getChanges())->except(['updated_at'])->all();

        if ($changes !== []) {
            $this->auditLogger->log('council.updated', $council, ['changes' => $changes]);
        }
    }

    public function deleted(MosqueCouncil $council): void
    {
        $this->auditLogger->log('council.deleted', $council, [
            'mosque_id' => $council->mosque_id,
            'name' => $council->name,
        ]);
    }
}
