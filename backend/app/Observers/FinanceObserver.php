<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class FinanceObserver
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
        $type = class_basename($record) === 'Subsidy' ? 'subsidy' : 'expense';
        $this->auditLogger->log('finance.'.$type.'.'.$action, $record, [
            'mosque_id' => $record->mosque_id,
            'status' => $record->status,
            'currency' => $record->currency,
        ]);
    }
}
