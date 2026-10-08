<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function __construct(public string $markup = '<p>Hello</p>', public string $subjectLine = 'Welcome')
    {
        $this->withSymfonyMessage(fn ($message) => $message->text('filament-mail-log-fixture-text'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->markup);
    }
}
