<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The plain mail of a bounce / complaint alert. It is logged like any mail, and a bounce of THIS mail never raises
 * another alert ({@see \Asignua\FilamentMailLog\Support\BounceAlerts}).
 */
class BounceAlertMail extends Mailable
{
    public function __construct(protected string $alertTitle, protected string $alertBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertTitle);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.nl2br(e($this->alertBody), false).'</p>');
    }
}
