<?php

namespace App\Notifications;

use App\Models\ApprovalQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalQueryRaised extends Notification
{
    use Queueable;

    public function __construct(private ApprovalQuery $query) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $po = $this->query->approval->approvable;

        return (new MailMessage)
            ->subject("Question about {$po->reference}")
            ->line("{$this->query->asker->name} asked: \"{$this->query->question}\"")
            ->action('Answer', url('/requisitions/'.$po->id))
            ->line("Regarding {$po->reference}.");
    }
}
