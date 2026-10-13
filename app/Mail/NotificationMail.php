<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationMail extends Mailable
{
    public function __construct(public Notification $n, public string $business) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->business.'] '.$this->n->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification', text: 'mail.notification-text', with: [
            'title' => $this->n->title, 'body' => $this->n->body, 'business' => $this->business,
            'link' => $this->n->url ? rtrim(config('app.url'), '/').$this->n->url : null,
            'prefs' => rtrim(config('app.url'), '/').'/profile',
        ]);
    }
}
