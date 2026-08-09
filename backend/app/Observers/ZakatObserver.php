<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ZakatObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function created(Model $record): void
    {
        $this->write('created', $record);
    }

    public function updated(Model $record): void
    {
        $this->write('updated', $record);
    }

    public function deleted(Model $record): void
    {
        $this->write('deleted', $record);
    }

    private function write(string $action, Model $record): void
    {
        $type = match (class_basename($record)) {
            'ZakatCollection' => 'collection', 'ZakatBeneficiary' => 'beneficiary', default => 'distribution'
        };
        $this->auditLogger->log('zakat.'.$type.'.'.$action, $record, ['mosque_id' => $record->mosque_id, 'status' => $record->status]);
    }
}
