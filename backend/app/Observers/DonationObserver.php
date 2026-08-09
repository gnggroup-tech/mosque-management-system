<?php

namespace App\Observers;

use App\Models\Donation;
use App\Services\AuditLogger;

class DonationObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function created(Donation $donation): void
    {
        $this->auditLogger->log('donation.created', $donation, [
            'mosque_id' => $donation->mosque_id,
            'receipt_number' => $donation->receipt_number,
            'status' => $donation->status,
        ]);
    }

    public function updated(Donation $donation): void
    {
        $fields = array_keys(collect($donation->getChanges())->except(['updated_at'])->all());
        if ($fields !== []) {
            $this->auditLogger->log('donation.updated', $donation, [
                'receipt_number' => $donation->receipt_number,
                'changed_fields' => $fields,
            ]);
        }
    }

    public function deleted(Donation $donation): void
    {
        $this->auditLogger->log('donation.deleted', $donation, [
            'mosque_id' => $donation->mosque_id,
            'receipt_number' => $donation->receipt_number,
        ]);
    }
}
