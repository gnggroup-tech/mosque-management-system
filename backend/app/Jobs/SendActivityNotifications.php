<?php

namespace App\Jobs;

use App\Services\ActivityNotificationMailSender;
use App\Services\ActivityNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendActivityNotifications implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $activityId,
        public readonly string $type,
        public readonly int $version,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        ActivityNotificationService $service,
        ActivityNotificationMailSender $sender,
    ): void {
        if (! $service->markQueued($this->activityId, $this->type, $this->version)) {
            return;
        }

        if (! $service->sendBatch($this->activityId, $this->type, $this->version, $this->attempts(), $sender)) {
            throw new RuntimeException('One or more activity notification transports failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ActivityNotificationService::class)->markFinalFailures(
            $this->activityId,
            $this->type,
            $this->version,
            $this->tries,
        );
    }
}
