<?php

namespace App\Notifications;

use App\Models\CouncilMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CouncilMeetingNoticeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CouncilMeeting $meeting) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Council meeting invitation'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('You are invited to the council meeting ":title".', [
                'title' => $this->meeting->title,
            ]))
            ->line(__('Scheduled for :date.', [
                'date' => $this->meeting->scheduled_at->translatedFormat('Y-m-d H:i'),
            ]));

        if ($this->meeting->location !== null && $this->meeting->location !== '') {
            $message->line(__('Location: :location.', ['location' => $this->meeting->location]));
        }

        return $message->line(__('This message confirms the meeting notice only; it is not a delivery or reading receipt.'));
    }
}
