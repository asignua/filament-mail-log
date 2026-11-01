<?php

declare(strict_types=1);

namespace Workbench\App\Notifications;

use Asignua\FilamentMailLog\Concerns\RelatesToModels;
use Asignua\FilamentMailLog\Contracts\RelatesToMailLog;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaid extends Notification implements RelatesToMailLog
{
    use RelatesToModels;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Payment received')->line('Thank you for your payment.');
    }
}
