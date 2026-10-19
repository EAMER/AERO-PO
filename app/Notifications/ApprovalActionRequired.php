<?php

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalActionRequired extends Notification
{
    use Queueable;

    public function __construct(private Approval $approval) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $po = $this->approval->approvable;

        return (new MailMessage)
            ->subject("Action needed: {$po->reference}")
            ->line("{$po->reference} is waiting on your {$this->approval->stage} approval (step {$this->approval->step_order}).")
            ->action('Review', url('/requisitions/'.$po->id))
            ->line('Approve, reject, or ask a question from the requisition page.');
    }
}
