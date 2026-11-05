<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ProcurementMessage extends Mailable
{
    public function __construct(public string $subjectLine, public string $bodyText, public ?string $pdfPath = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.procurement');
    }

    public function attachments(): array
    {
        return $this->pdfPath ? [Attachment::fromStorageDisk('private', $this->pdfPath)->as('purchase-order.pdf')->withMime('application/pdf')] : [];
    }
}
