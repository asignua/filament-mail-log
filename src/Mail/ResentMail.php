<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * A stored message sent again. It carries the stored copy (subject, bodies, sender, reply-to), never the
 * original mailable: that class may be gone or need models that were deleted.
 *
 * The public property reaches the listener through the mailable's view data, which is how the new log row
 * learns which row it was resent from.
 */
class ResentMail extends Mailable
{
    /**
     * @param array<string, string> $extraHeaders name => value, only the few that are safe to copy
     */
    public function __construct(
        public int $mailLogResentFrom,
        protected ?string $storedSubject,
        protected ?string $storedHtml,
        protected ?string $storedText,
        protected ?string $storedFrom,
        protected ?string $storedReplyTo,
        protected array $extraHeaders = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->address($this->storedFrom),
            replyTo: array_filter([$this->address($this->storedReplyTo)]),
            subject: $this->storedSubject ?? '',
        );
    }

    public function build(): static
    {
        // The mailer refuses a message with no view at all, and Mailable::text() takes a view NAME, so the
        // placeholder html below only satisfies it; the real parts go straight onto the Symfony message.
        $this->html((string) $this->storedHtml);

        return $this->withSymfonyMessage(function (Email $message): void {
            $message->html(filled($this->storedHtml) ? (string) $this->storedHtml : null);
            $message->text(filled($this->storedText) ? (string) $this->storedText : null);

            foreach ($this->extraHeaders as $name => $value) {
                $message->getHeaders()->addTextHeader($name, $value);
            }
        });
    }

    private function address(?string $value): ?Address
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $parsed = MimeAddress::create($value);

            return new Address($parsed->getAddress(), $parsed->getName());
        } catch (Throwable) {
            return null;
        }
    }
}
