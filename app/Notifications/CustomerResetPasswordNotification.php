<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class CustomerResetPasswordNotification extends ResetPassword
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendBaseUrl = rtrim((string) config('workatmo.frontend_url', config('app.url')), '/');
        $resetUrl = $frontendBaseUrl.'/account/reset-password?token='.$this->token.'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Matrix account password')
            ->greeting('Hello!')
            ->line('We received a request to reset your account password.')
            ->action('Reset Password', $resetUrl)
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}

