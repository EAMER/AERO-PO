<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $heading, public string $text, public ?string $link = null)
    {
        $this->afterCommit();
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(str_replace(["\r", "\n"], ' ', $this->heading))->line(e($this->text));
        if ($this->link) {
            $mail->action('View requisition', $this->link);
        }

        return $mail;
    }
}
