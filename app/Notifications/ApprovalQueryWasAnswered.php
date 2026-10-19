<?php

namespace App\Notifications;

use App\Models\ApprovalQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalQueryWasAnswered extends Notification
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
            ->subject("Answered: {$po->reference}")
            ->line("{$this->query->target->name} answered your question: \"{$this->query->answer}\"")
            ->action('View', url('/requisitions/'.$po->id));
    }
}
