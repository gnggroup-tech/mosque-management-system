<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class WaqfObserver
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
            'WaqfAsset' => 'asset',
            'WaqfRevenue' => 'revenue',
            default => 'expense',
        };
        $asset = $type === 'asset' ? $record : $record->asset;
        $this->auditLogger->log('waqf.'.$type.'.'.$action, $record, [
            'mosque_id' => $asset->mosque_id,
            'status' => $record->status,
        ]);
    }
}
