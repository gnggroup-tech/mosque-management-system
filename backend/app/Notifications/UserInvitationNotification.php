<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your account invitation'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('You have been invited to create an account.'))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This invitation expires at :date.', [
                'date' => $this->expiresAt->translatedFormat('Y-m-d H:i'),
            ]))
            ->line(__('If you did not expect this invitation, no action is required.'));
    }
}
