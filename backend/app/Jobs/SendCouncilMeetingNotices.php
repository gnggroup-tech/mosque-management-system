<?php

namespace App\Jobs;

use App\Services\CouncilMeetingNoticeMailSender;
use App\Services\CouncilMeetingNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendCouncilMeetingNotices implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $participantVersions
     */
    public function __construct(
        public readonly int $meetingId,
        public readonly int $noticeVersion,
        public readonly array $participantVersions,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        CouncilMeetingNoticeService $noticeService,
        CouncilMeetingNoticeMailSender $mailSender,
    ): void {
        if (! $noticeService->markQueued($this->meetingId, $this->noticeVersion, $this->participantVersions)) {
            return;
        }

        $failed = false;
        foreach ($this->participantVersions as $participantId => $deliveryVersion) {
            if (! $noticeService->sendParticipant(
                $this->meetingId,
                $this->noticeVersion,
                (int) $participantId,
                $deliveryVersion,
                $this->attempts(),
                $mailSender,
            )) {
                $failed = true;
            }
        }

        $noticeService->markSentWhenComplete($this->meetingId, $this->noticeVersion);

        if ($failed) {
            throw new RuntimeException('One or more council meeting notice transports failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(CouncilMeetingNoticeService::class)->markFinalFailures(
            $this->meetingId,
            $this->noticeVersion,
            $this->participantVersions,
            $this->tries,
        );
    }
}
