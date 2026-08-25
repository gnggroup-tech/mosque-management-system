<?php

namespace App\Services;

use App\Models\CouncilMeeting;
use App\Models\User;
use App\Notifications\CouncilMeetingNoticeNotification;
use Illuminate\Support\Facades\Notification;

class CouncilMeetingNoticeMailSender
{
    public function send(User $account, CouncilMeeting $meeting): void
    {
        Notification::locale($account->locale)->sendNow(
            $account,
            new CouncilMeetingNoticeNotification($meeting),
            ['mail'],
        );
    }
}
