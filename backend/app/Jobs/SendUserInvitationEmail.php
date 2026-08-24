<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\UserInvitation;
use App\Services\UserInvitationMailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendUserInvitationEmail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $invitationId,
        public readonly int $deliveryVersion,
        private readonly string $token,
        public readonly string $locale,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(UserInvitationMailSender $mailSender): void
    {
        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $invitation = UserInvitation::query()
                ->with('user')
                ->lockForUpdate()
                ->find($this->invitationId);

            if (! $this->isCurrent($invitation)) {
                DB::commit();

                return;
            }

            $invitation->forceFill([
                'queue_claimed_at' => null,
                'queued_at' => $invitation->queued_at ?? now(),
                'delivery_attempts' => max($invitation->delivery_attempts, $this->attempts()),
            ])->save();

            try {
                $mailSender->send(
                    $invitation->user,
                    $this->token,
                    $invitation->expires_at,
                    $this->locale,
                );
            } catch (Throwable) {
                DB::commit();

                throw new RuntimeException('The invitation email transport failed.');
            }

            $invitation->forceFill([
                'sent_at' => now(),
                'failed_at' => null,
            ])->save();

            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $invitation = UserInvitation::query()->lockForUpdate()->find($this->invitationId);

            if (! $this->isSameDelivery($invitation) || $invitation->sent_at !== null) {
                return;
            }

            $invitation->forceFill([
                'delivery_attempts' => max($invitation->delivery_attempts, $this->tries),
                'failed_at' => now(),
            ])->save();
        });
    }

    private function isCurrent(?UserInvitation $invitation): bool
    {
        return $this->isSameDelivery($invitation)
            && $invitation->accepted_at === null
            && $invitation->expires_at->isFuture()
            && $invitation->sent_at === null
            && $invitation->user !== null
            && $invitation->user->status === AccountStatus::PendingEmail;
    }

    private function isSameDelivery(?UserInvitation $invitation): bool
    {
        return $invitation !== null
            && $invitation->delivery_version === $this->deliveryVersion
            && hash_equals($invitation->token_hash, hash('sha256', $this->token));
    }
}
