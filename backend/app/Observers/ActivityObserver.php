<?php

namespace App\Observers;

use App\Models\Activity;
use App\Services\AuditLogger;

class ActivityObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function created(Activity $activity): void
    {
        $this->auditLogger->log('activity.created', $activity, ['mosque_id' => $activity->mosque_id, 'type' => $activity->type, 'status' => $activity->status]);
    }

    public function updated(Activity $activity): void
    {
        $fields = array_keys(collect($activity->getChanges())->except(['updated_at'])->all());
        if ($fields !== []) {
            $this->auditLogger->log('activity.updated', $activity, ['mosque_id' => $activity->mosque_id, 'changed_fields' => $fields]);
        }
    }

    public function deleted(Activity $activity): void
    {
        $this->auditLogger->log('activity.deleted', $activity, ['mosque_id' => $activity->mosque_id, 'title' => $activity->title]);
    }
}
