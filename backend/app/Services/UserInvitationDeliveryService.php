<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Jobs\SendUserInvitationEmail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class UserInvitationDeliveryService
{
    public function queue(UserInvitation $invitation, User $account, string $token): bool
    {
        $claim = DB::transaction(function () use ($invitation, $account, $token): ?array {
            $locked = UserInvitation::query()->lockForUpdate()->find($invitation->getKey());
            $lockedAccount = $locked === null
                ? null
                : User::query()->lockForUpdate()->find($locked->user_id);

            if ($locked === null
                || $lockedAccount === null
                || $lockedAccount->getKey() !== $account->getKey()
                || $lockedAccount->status !== AccountStatus::PendingEmail
                || $locked->accepted_at !== null
                || $locked->expires_at->isPast()
                || ! hash_equals($locked->token_hash, hash('sha256', $token))
                || $locked->queued_at !== null
                || $locked->queue_claimed_at !== null) {
                return null;
            }

            $locked->forceFill(['queue_claimed_at' => now()])->save();

            return ['invitation' => $locked, 'locale' => $lockedAccount->locale];
        });

        if ($claim === null) {
            return false;
        }

        /** @var UserInvitation $claimed */
        $claimed = $claim['invitation'];

        try {
            Bus::dispatch(new SendUserInvitationEmail(
                $claimed->getKey(),
                $claimed->delivery_version,
                $token,
                $claim['locale'],
            ));

            UserInvitation::query()
                ->whereKey($claimed->getKey())
                ->where('delivery_version', $claimed->delivery_version)
                ->whereNotNull('queue_claimed_at')
                ->update([
                    'queue_claimed_at' => null,
                    'queued_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            UserInvitation::query()
                ->whereKey($claimed->getKey())
                ->where('delivery_version', $claimed->delivery_version)
                ->update([
                    'queue_claimed_at' => null,
                    'updated_at' => now(),
                ]);

            throw new RuntimeException('The invitation email could not be queued.');
        }

        return true;
    }
}
