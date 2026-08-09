<?php

namespace App\Observers;

use App\Models\Announcement;
use App\Services\AuditLogger;

class AnnouncementObserver
{
    public function __construct(private readonly AuditLogger $auditLogger) {}
    public function created(Announcement $announcement): void
    {
        $this->auditLogger->log('announcement.created', $announcement, ['mosque_id' => $announcement->mosque_id, 'type' => $announcement->type, 'audience' => $announcement->audience]);
    }
    public function updated(Announcement $announcement): void
    {
        $fields = array_keys(collect($announcement->getChanges())->except(['updated_at'])->all());
        if ($fields !== []) { $this->auditLogger->log('announcement.updated', $announcement, ['mosque_id' => $announcement->mosque_id, 'changed_fields' => $fields]); }
    }
    public function deleted(Announcement $announcement): void
    {
        $this->auditLogger->log('announcement.deleted', $announcement, ['mosque_id' => $announcement->mosque_id, 'title' => $announcement->title]);
    }
}
