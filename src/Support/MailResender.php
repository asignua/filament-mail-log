<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Mail\ResentMail;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a stored message again, from the STORED copy: redacted bodies (so signed links in them are dead),
 * no attachments (only their names were ever stored), headers limited to a short allow-list.
 */
final class MailResender
{
    /** Headers that are copied from the stored ones (lower case => canonical name). Reply-To has its own path. */
    private const array COPIED_HEADERS = ['x-priority' => 'X-Priority', 'importance' => 'Importance'];

    public function __construct(private readonly MailLogRepository $repository) {}

    /**
     * True when there is anything to send and the schema can record the link.
     */
    public function isAvailable(MailLog $log): bool
    {
        return (bool) config('filament-mail-log.resend.enabled', true)
            && MailLog::hasResendColumn()
            && (filled($log->html_body) || filled($log->text_body));
    }

    public function resentCount(MailLog $log): int
    {
        return MailLog::hasResendColumn() ? $this->repository->countResends((int) $log->getKey()) : 0;
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public function resend(MailLog $log, array $to, array $cc = [], array $bcc = []): void
    {
        $mailable = new ResentMail(
            mailLogResentFrom: (int) $log->getKey(),
            storedSubject: $log->subject,
            storedHtml: $log->html_body,
            storedText: $log->text_body,
            storedFrom: $log->sender,
            storedReplyTo: $log->headers['Reply-To'][0] ?? null,
            extraHeaders: $this->copiedHeaders($log),
        );

        $pending = Mail::mailer($this->mailer())->to($to);

        if ($cc !== []) {
            $pending->cc($cc);
        }

        if ($bcc !== []) {
            $pending->bcc($bcc);
        }

        (bool) config('filament-mail-log.resend.queue', false) ? $pending->queue($mailable) : $pending->send($mailable);
    }

    private function mailer(): ?string
    {
        $mailer = config('filament-mail-log.resend.mailer');

        return is_string($mailer) && $mailer !== '' ? $mailer : null;
    }

    /**
     * @return array<string, string>
     */
    private function copiedHeaders(MailLog $log): array
    {
        $rows = [];

        foreach ($log->headers ?? [] as $name => $values) {
            $canonical = self::COPIED_HEADERS[strtolower((string) $name)] ?? null;

            if ($canonical !== null && isset($values[0]) && $values[0] !== '') {
                $rows[$canonical] = $values[0];
            }
        }

        return $rows;
    }
}
