<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed')
            ->line('The password for your account was just changed.')
            ->line('If this was you, no action is needed.')
            ->line('If it was not you, reset your password right away and contact support.');
    }
}