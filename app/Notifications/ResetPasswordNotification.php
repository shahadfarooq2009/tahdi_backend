<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $resetUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور — منصة تحدي الطلبة')
            ->greeting('مرحباً')
            ->line('تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك.')
            ->action('إعادة تعيين كلمة المرور', $this->resetUrl)
            ->line('إذا لم تطلب إعادة التعيين، تجاهل هذه الرسالة.');
    }
}
