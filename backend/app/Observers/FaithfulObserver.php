<?php

namespace App\Observers;

use App\Models\Faithful;
use App\Services\AuditLogger;

class FaithfulObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function created(Faithful $faithful): void
    {
        $this->auditLogger->log('faithful.created', $faithful, ['mosque_id' => $faithful->mosque_id, 'registration_number' => $faithful->registration_number]);
    }

    public function updated(Faithful $faithful): void
    {
        $fields = array_keys(collect($faithful->getChanges())->except(['updated_at'])->all());
        if ($fields !== []) {
            $this->auditLogger->log('faithful.updated', $faithful, ['changed_fields' => $fields]);
        }
    }

    public function deleted(Faithful $faithful): void
    {
        $this->auditLogger->log('faithful.deleted', $faithful, ['mosque_id' => $faithful->mosque_id, 'registration_number' => $faithful->registration_number]);
    }
}
