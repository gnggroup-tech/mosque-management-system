<?php

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Activity $activity,
        private readonly string $type,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->type) {
            'updated' => __('Activity schedule updated'),
            'cancelled' => __('Activity cancelled'),
            default => __('Activity reminder'),
        };
        $introduction = match ($this->type) {
            'updated' => __('The schedule or location of the activity ":title" has changed.', ['title' => $this->activity->title]),
            'cancelled' => __('The activity ":title" has been cancelled.', ['title' => $this->activity->title]),
            default => __('Reminder: the activity ":title" starts within 24 hours.', ['title' => $this->activity->title]),
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($introduction)
            ->line(__('Scheduled for :date.', [
                'date' => $this->activity->starts_at->timezone(config('app.timezone'))->translatedFormat('Y-m-d H:i'),
            ]))
            ->when(
                filled($this->activity->location),
                fn (MailMessage $message) => $message->line(__('Location: :location.', ['location' => $this->activity->location])),
            )
            ->line(__('This message is an application notice; it is not a delivery or reading receipt.'));
    }
}
