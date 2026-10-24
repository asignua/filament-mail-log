<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Listeners;

use Asignua\FilamentMailLog\Repositories\DeliveryRepository;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Support\TrackingSchema;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * With `suppression.enabled`: removes the addresses of the suppression list from To / Cc / Bcc, and cancels a
 * message that is left with nobody to send to (its log row becomes `suppressed`).
 *
 * Registered AFTER {@see LogOutgoingMail}, so the row and its correlation header already exist.
 *
 * RULE: this is the one listener of the plugin that may return false (it cancels the message). A failure of
 * the lookup itself never does: an unreadable list must not stop the application's mail.
 */
final class DropSuppressedRecipients
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly MailLogRepository $logs,
    ) {}

    public function handle(MessageSending $event): ?bool
    {
        try {
            if (!(bool) config('filament-mail-log.suppression.enabled', false) || !TrackingSchema::hasSuppressionsTable()) {
                return null;
            }

            $email = $event->message;

            $lists = ['To' => $email->getTo(), 'Cc' => $email->getCc(), 'Bcc' => $email->getBcc()];
            $all = [];

            foreach ($lists as $addresses) {
                foreach ($addresses as $address) {
                    $all[] = mb_strtolower($address->getAddress());
                }
            }

            $suppressed = $this->deliveries->suppressedAmong($all);

            if ($suppressed === []) {
                return null;
            }

            $kept = 0;

            foreach ($lists as $name => $addresses) {
                $left = array_values(array_filter($addresses, fn (Address $address): bool => !in_array(mb_strtolower($address->getAddress()), $suppressed, true)));
                $kept += count($left);

                if (count($left) === count($addresses)) {
                    continue;
                }

                // An empty list is removed, not written back empty: a bare "To:" header is invalid.
                if ($left === []) {
                    $email->getHeaders()->remove($name);
                } elseif ($name === 'To') {
                    $email->to(...$left);
                } elseif ($name === 'Cc') {
                    $email->cc(...$left);
                } else {
                    $email->bcc(...$left);
                }
            }

            if ($kept > 0) {
                return null;
            }

            $this->cancel($email, $suppressed);

            return false;
        } catch (Throwable $e) {
            Log::warning('filament-mail-log: could not check the suppression list: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param list<string> $suppressed
     */
    private function cancel(Email $email, array $suppressed): void
    {
        $header = config('filament-mail-log.header', 'X-Mail-Log-Id');
        $ulid = $email->getHeaders()->get(is_string($header) && $header !== '' ? $header : 'X-Mail-Log-Id')?->getBodyAsString();

        if ($ulid === null || $ulid === '') {
            return;
        }

        // The row must not be failed at the end of the process as "never answered": it was cancelled on purpose.
        MailLogContext::forget($ulid);
        $this->logs->markSuppressed($ulid, 'Every recipient is on the suppression list: '.implode(', ', $suppressed));
    }
}
