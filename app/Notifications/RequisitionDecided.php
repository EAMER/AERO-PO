<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the requester when their PO reaches a terminal state: closed or rejected. */
class RequisitionDecided extends Notification
{
    use Queueable;

    public function __construct(private PurchaseOrder $po) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $closed = $this->po->status->value === 'closed';

        $mail = (new MailMessage)->subject("{$this->po->reference} is {$this->po->status->value}");

        return $closed
            ? $mail->line("Your requisition {$this->po->reference} has been closed and fulfilled.")
            : $mail->line("Your requisition {$this->po->reference} was rejected.")
                   ->line('Check the status history on the requisition page for the reason.');
    }
}
