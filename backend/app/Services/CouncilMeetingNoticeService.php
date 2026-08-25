<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Jobs\SendCouncilMeetingNotices;
use App\Models\CouncilMeeting;
use App\Models\CouncilMeetingParticipant;
use App\Models\CouncilMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CouncilMeetingNoticeService
{
    private const CLAIM_TIMEOUT_SECONDS = 300;

    /** @return array<string, mixed> */
    public function queue(CouncilMeeting $meeting): array
    {
        $preparation = $this->prepare($meeting->getKey());

        if ($preparation['participant_versions'] === []) {
            return $this->result($meeting->getKey());
        }

        try {
            Bus::dispatch(new SendCouncilMeetingNotices(
                $meeting->getKey(),
                $preparation['notice_version'],
                $preparation['participant_versions'],
            ));

            $this->markQueued(
                $meeting->getKey(),
                $preparation['notice_version'],
                $preparation['participant_versions'],
            );
        } catch (Throwable) {
            $this->markQueueFailure(
                $meeting->getKey(),
                $preparation['notice_version'],
                $preparation['participant_versions'],
            );

            throw new RuntimeException('Council meeting notices could not be queued.');
        }

        return $this->result($meeting->getKey());
    }

    /** @param array<int, int> $participantVersions */
    public function markQueued(int $meetingId, int $noticeVersion, array $participantVersions): bool
    {
        return DB::transaction(function () use ($meetingId, $noticeVersion, $participantVersions): bool {
            $meeting = CouncilMeeting::query()->lockForUpdate()->find($meetingId);
            if ($meeting === null
                || $meeting->notice_version !== $noticeVersion
                || ! in_array($meeting->status, ['draft', 'convened'], true)) {
                return false;
            }

            $participants = CouncilMeetingParticipant::query()
                ->where('council_meeting_id', $meetingId)
                ->whereIn('id', array_keys($participantVersions))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($participantVersions as $participantId => $deliveryVersion) {
                $participant = $participants->get($participantId);
                if ($participant === null || $participant->notice_delivery_version !== $deliveryVersion) {
                    return false;
                }
            }

            $queuedAt = now();
            foreach ($participantVersions as $participantId => $deliveryVersion) {
                $participant = $participants->get($participantId);
                $participant->forceFill([
                    'notice_queue_claimed_at' => null,
                    'notice_queued_at' => $participant->notice_queued_at ?? $queuedAt,
                ])->save();
            }

            $meeting->forceFill([
                'status' => 'convened',
                'notice_queue_claimed_at' => null,
                'notice_queued_at' => $meeting->notice_queued_at ?? $queuedAt,
            ])->save();

            return true;
        });
    }

    public function sendParticipant(
        int $meetingId,
        int $noticeVersion,
        int $participantId,
        int $deliveryVersion,
        int $attempt,
        CouncilMeetingNoticeMailSender $mailSender,
    ): bool {
        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $meeting = CouncilMeeting::query()->lockForUpdate()->find($meetingId);
            $participant = CouncilMeetingParticipant::query()->lockForUpdate()->find($participantId);

            if ($meeting === null
                || $meeting->notice_version !== $noticeVersion
                || $meeting->status !== 'convened'
                || $participant === null
                || $participant->council_meeting_id !== $meetingId
                || $participant->notice_delivery_version !== $deliveryVersion
                || $participant->notice_queued_at === null
                || $participant->notice_sent_at !== null) {
                DB::commit();

                return true;
            }

            $member = CouncilMember::withTrashed()->with('user')->find($participant->council_member_id);
            $participant->forceFill([
                'notice_attempts' => max($participant->notice_attempts, $attempt),
            ])->save();

            if ($this->ineligibilityReason($member) !== null) {
                DB::commit();

                return false;
            }

            try {
                $mailSender->send($member->user, $meeting);
            } catch (Throwable) {
                DB::commit();

                return false;
            }

            $participant->forceFill([
                'notice_sent_at' => now(),
                'notice_failed_at' => null,
            ])->save();
            DB::commit();

            return true;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    public function markSentWhenComplete(int $meetingId, int $noticeVersion): void
    {
        DB::transaction(function () use ($meetingId, $noticeVersion): void {
            $meeting = CouncilMeeting::query()->lockForUpdate()->find($meetingId);
            if ($meeting === null
                || $meeting->notice_version !== $noticeVersion
                || $meeting->status !== 'convened') {
                return;
            }

            $unsent = CouncilMeetingParticipant::query()
                ->where('council_meeting_id', $meetingId)
                ->whereNotNull('notice_queued_at')
                ->whereNull('notice_sent_at')
                ->exists();

            if (! $unsent && $meeting->notice_sent_at === null) {
                $meeting->forceFill(['notice_sent_at' => now()])->save();
            }
        });
    }

    /** @param array<int, int> $participantVersions */
    public function markFinalFailures(
        int $meetingId,
        int $noticeVersion,
        array $participantVersions,
        int $attempts,
    ): void {
        DB::transaction(function () use ($meetingId, $noticeVersion, $participantVersions, $attempts): void {
            $meeting = CouncilMeeting::query()->lockForUpdate()->find($meetingId);
            if ($meeting === null || $meeting->notice_version !== $noticeVersion) {
                return;
            }

            $participants = CouncilMeetingParticipant::query()
                ->where('council_meeting_id', $meetingId)
                ->whereIn('id', array_keys($participantVersions))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($participantVersions as $participantId => $deliveryVersion) {
                $participant = $participants->get($participantId);
                if ($participant === null
                    || $participant->notice_delivery_version !== $deliveryVersion
                    || $participant->notice_sent_at !== null) {
                    continue;
                }

                $participant->forceFill([
                    'notice_attempts' => max($participant->notice_attempts, $attempts),
                    'notice_failed_at' => now(),
                ])->save();
            }
        });
    }

    /** @return array{notice_version: int, participant_versions: array<int, int>} */
    private function prepare(int $meetingId): array
    {
        return DB::transaction(function () use ($meetingId): array {
            $meeting = CouncilMeeting::query()->lockForUpdate()->findOrFail($meetingId);
            if (! in_array($meeting->status, ['draft', 'convened'], true)) {
                throw ValidationException::withMessages([
                    'meeting' => __('This meeting cannot be convened in its current status.'),
                ]);
            }

            if ($meeting->notice_queue_claimed_at !== null
                && $meeting->notice_queue_claimed_at->isAfter(now()->subSeconds(self::CLAIM_TIMEOUT_SECONDS))) {
                return [
                    'notice_version' => $meeting->notice_version,
                    'participant_versions' => [],
                ];
            }

            if ($meeting->notice_queue_claimed_at !== null) {
                CouncilMeetingParticipant::query()
                    ->where('council_meeting_id', $meetingId)
                    ->whereNotNull('notice_queue_claimed_at')
                    ->update(['notice_queue_claimed_at' => null, 'updated_at' => now()]);
            }

            $participants = CouncilMeetingParticipant::query()
                ->where('council_meeting_id', $meetingId)
                ->lockForUpdate()
                ->get();
            $members = CouncilMember::withTrashed()
                ->with('user')
                ->whereIn('id', $participants->pluck('council_member_id'))
                ->get()
                ->keyBy('id');

            $eligible = $participants->filter(
                fn (CouncilMeetingParticipant $participant): bool => $this->ineligibilityReason(
                    $members->get($participant->council_member_id)
                ) === null
            );

            if ($meeting->status === 'draft' && $eligible->isEmpty()) {
                $errors = $this->ineligibleParticipants($participants, $members)
                    ->map(fn (array $item): string => __('Participant :id is ineligible for the notice (:reason).', [
                        'id' => $item['participant_id'],
                        'reason' => $item['reason'],
                    ]))
                    ->all();

                throw ValidationException::withMessages([
                    'participants' => $errors !== []
                        ? $errors
                        : [__('No selected participant is eligible for an e-mail notice.')],
                ]);
            }

            $targets = $meeting->status === 'draft'
                ? $eligible
                : $eligible->filter(fn (CouncilMeetingParticipant $participant): bool => $participant->notice_sent_at === null
                    && $participant->notice_failed_at !== null);

            if ($targets->isEmpty()) {
                return [
                    'notice_version' => $meeting->notice_version,
                    'participant_versions' => [],
                ];
            }

            $noticeVersion = $meeting->notice_version + 1;
            $claimedAt = now();
            $meeting->forceFill([
                'notice_version' => $noticeVersion,
                'notice_queue_claimed_at' => $claimedAt,
                'notice_sent_at' => null,
            ])->save();

            $participantVersions = [];
            foreach ($targets as $participant) {
                $deliveryVersion = $participant->notice_delivery_version + 1;
                $participant->forceFill([
                    'notice_delivery_version' => $deliveryVersion,
                    'notice_queue_claimed_at' => $claimedAt,
                    'notice_queued_at' => null,
                    'notice_sent_at' => null,
                    'notice_failed_at' => null,
                    'notice_attempts' => 0,
                ])->save();
                $participantVersions[$participant->getKey()] = $deliveryVersion;
            }

            return [
                'notice_version' => $noticeVersion,
                'participant_versions' => $participantVersions,
            ];
        });
    }

    /** @param array<int, int> $participantVersions */
    private function markQueueFailure(int $meetingId, int $noticeVersion, array $participantVersions): void
    {
        DB::transaction(function () use ($meetingId, $noticeVersion, $participantVersions): void {
            $meeting = CouncilMeeting::query()->lockForUpdate()->find($meetingId);
            if ($meeting === null || $meeting->notice_version !== $noticeVersion) {
                return;
            }

            $meeting->forceFill(['notice_queue_claimed_at' => null])->save();
            $participants = CouncilMeetingParticipant::query()
                ->where('council_meeting_id', $meetingId)
                ->whereIn('id', array_keys($participantVersions))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($participantVersions as $participantId => $deliveryVersion) {
                $participant = $participants->get($participantId);
                if ($participant === null || $participant->notice_delivery_version !== $deliveryVersion) {
                    continue;
                }

                $participant->forceFill([
                    'notice_queue_claimed_at' => null,
                    'notice_failed_at' => now(),
                ])->save();
            }
        });
    }

    /** @return array<string, mixed> */
    private function result(int $meetingId): array
    {
        $meeting = CouncilMeeting::query()->findOrFail($meetingId);
        $participants = CouncilMeetingParticipant::query()
            ->where('council_meeting_id', $meetingId)
            ->get();
        $members = CouncilMember::withTrashed()
            ->with('user')
            ->whereIn('id', $participants->pluck('council_member_id'))
            ->get()
            ->keyBy('id');

        return [
            'queued_count' => $participants->whereNotNull('notice_queued_at')->count(),
            'sent_count' => $participants->whereNotNull('notice_sent_at')->count(),
            'failed_count' => $participants->whereNotNull('notice_failed_at')->count(),
            'ineligible_participants' => $this->ineligibleParticipants($participants, $members)->values()->all(),
        ];
    }

    private function ineligibilityReason(?CouncilMember $member): ?string
    {
        if ($member === null || $member->trashed() || $member->status !== 'active') {
            return 'member_inactive';
        }

        if ($member->user === null) {
            return 'account_unavailable';
        }

        if ($member->user->status !== AccountStatus::Active) {
            return 'account_inactive';
        }

        if (trim((string) $member->user->email) === '') {
            return 'email_unavailable';
        }

        return null;
    }

    /**
     * @param  Collection<int, CouncilMeetingParticipant>  $participants
     * @param  Collection<int, CouncilMember>  $members
     * @return Collection<int, array{participant_id: int, reason: string}>
     */
    private function ineligibleParticipants(Collection $participants, Collection $members): Collection
    {
        return $participants->map(function (CouncilMeetingParticipant $participant) use ($members): ?array {
            $reason = $this->ineligibilityReason($members->get($participant->council_member_id));

            return $reason === null ? null : [
                'participant_id' => $participant->getKey(),
                'reason' => $reason,
            ];
        })->filter();
    }
}
