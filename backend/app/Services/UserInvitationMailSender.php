<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

class UserInvitationMailSender
{
    public function send(
        User $account,
        string $token,
        CarbonInterface $expiresAt,
        string $locale,
    ): void {
        Notification::locale($locale)->sendNow(
            $account,
            new UserInvitationNotification($token, $expiresAt),
            ['mail'],
        );
    }
}
