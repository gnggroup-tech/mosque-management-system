<?php

namespace App\Console\Commands;

use App\Services\ActivityNotificationService;
use Illuminate\Console\Command;

class QueueActivityReminders extends Command
{
    protected $signature = 'sgar:activities:queue-reminders';

    protected $description = 'Queue due 24-hour activity reminders for active registered accounts';

    public function handle(ActivityNotificationService $service): int
    {
        $queued = $service->queueDueReminders();
        $this->components->info(__('Activity reminder batches queued: :count', ['count' => $queued]));

        return self::SUCCESS;
    }
}
