<?php

namespace App\Observers;

use App\Models\CouncilDecision;
use App\Models\CouncilMeeting;
use App\Services\AuditLogger;

class CouncilMeetingObserver
{
    public function created(CouncilMeeting|CouncilDecision $model): void
    {
        $this->log($model, 'created');
    }

    public function updated(CouncilMeeting|CouncilDecision $model): void
    {
        $this->log($model, 'updated');
    }

    public function deleted(CouncilMeeting|CouncilDecision $model): void
    {
        $this->log($model, 'deleted');
    }

    private function log(CouncilMeeting|CouncilDecision $model, string $action): void
    {
        app(AuditLogger::class)->log(($model instanceof CouncilMeeting ? 'council-meeting.' : 'council-decision.').$action, $model, [
            'mosque_council_id' => $model instanceof CouncilMeeting ? $model->mosque_council_id : $model->meeting?->mosque_council_id,
            'status' => $model instanceof CouncilMeeting ? $model->status : $model->outcome,
        ]);
    }
}
