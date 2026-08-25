<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\Notification;

class ActivityNotificationMailSender
{
    public function send(User $account, Activity $activity, string $type): void
    {
        Notification::locale($account->locale)->sendNow(
            $account,
            new ActivityNotification($activity, $type),
            ['mail'],
        );
    }
}
