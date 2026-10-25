<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * Postmark. Webhooks are not signed: Postmark calls the URL with the HTTP Basic credentials you put in it
 * (`https://user:password@host/mail-log/webhooks/postmark`), so that is what is verified.
 *
 * Correlation: `MessageID` (the id the Postmark API transport returns) and `Metadata.mail_log_id`.
 * One event per request (Delivery, Bounce, SpamComplaint, Open, Click).
 */
final class PostmarkDriver extends AbstractDriver
{
    /** Bounce types that mean "this address is wrong or blocked for good". */
    private const array HARD = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'];

    /** Bounce types that are not failures of the message at all. */
    private const array IGNORED = ['Subscribe', 'Unsubscribe', 'AutoResponder', 'ChallengeVerification', 'OpenRelayTest', 'AddressChange'];

    public function verify(Request $request): void
    {
        $user = $this->option('username');
        $password = $this->option('password');

        if ($user === '' || $password === '') {
            throw InvalidWebhook::forbidden();
        }

        $header = (string) $request->header('Authorization', '');

        if (stripos($header, 'basic ') !== 0) {
            throw InvalidWebhook::forbidden();
        }

        $decoded = base64_decode(substr($header, 6), true);

        $ok = $decoded !== false && hash_equals($user.':'.$password, $decoded);

        if (!$ok) {
            throw InvalidWebhook::forbidden();
        }
    }

    public function events(Request $request): array
    {
        $data = $this->json($request);
        $record = $data['RecordType'] ?? null;

        $event = match ($record) {
            'Delivery' => $this->event($data, DeliveryEventType::Delivered, $data['Recipient'] ?? null, $data['DeliveredAt'] ?? null),
            'Open' => $this->event($data, DeliveryEventType::Opened, $data['Recipient'] ?? null, $data['ReceivedAt'] ?? null),
            'Click' => $this->event($data, DeliveryEventType::Clicked, $data['Recipient'] ?? null, $data['ReceivedAt'] ?? null, null, $data['OriginalLink'] ?? null),
            'SpamComplaint' => $this->event($data, DeliveryEventType::Complained, $data['Email'] ?? null, $data['BouncedAt'] ?? null, null, $data['Details'] ?? null),
            'Bounce' => $this->bounce($data),
            default => null,
        };

        return $event === null ? [] : [$event];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function bounce(array $data): ?DeliveryEvent
    {
        $type = is_string($data['Type'] ?? null) ? $data['Type'] : '';

        if (in_array($type, self::IGNORED, true)) {
            return null;
        }

        // `SpamNotification` is a receiving server calling the message spam, not the recipient complaining: it is a
        // soft bounce below and never suppresses. `SpamComplaint` is the recipient.
        if ($type === 'SpamComplaint') {
            return $this->event($data, DeliveryEventType::Complained, $data['Email'] ?? null, $data['BouncedAt'] ?? null, null, $data['Description'] ?? null);
        }

        return $this->event(
            $data,
            DeliveryEventType::Bounced,
            $data['Email'] ?? null,
            $data['BouncedAt'] ?? null,
            in_array($type, self::HARD, true) ? BounceType::Hard : BounceType::Soft,
            $data['Details'] ?? $data['Description'] ?? null,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function event(array $data, DeliveryEventType $type, mixed $recipient, mixed $at, ?BounceType $bounce = null, mixed $detail = null): DeliveryEvent
    {
        $time = $this->time($at);
        $recipient = is_string($recipient) ? strtolower(trim($recipient)) : null;
        $metadata = is_array($data['Metadata'] ?? null) ? $data['Metadata'] : [];

        // Bounces and complaints carry an ID; delivery / open / click do not, so they get a stable fingerprint.
        $id = isset($data['ID']) && is_scalar($data['ID'])
            ? (string) $data['ID']
            : sha1(implode('|', [(string) ($data['RecordType'] ?? ''), (string) ($data['MessageID'] ?? ''), (string) $recipient, (string) $time->getTimestamp(), (string) ($data['OriginalLink'] ?? '')]));

        $messageId = $this->messageId($data['MessageID'] ?? null);
        $logId = $this->logId($metadata['mail_log_id'] ?? null);

        return new DeliveryEvent(
            provider: $this->name,
            id: $id,
            type: $type,
            occurredAt: $time,
            recipient: $recipient,
            bounce: $bounce,
            messageIds: $messageId === null ? [] : [$messageId],
            logIds: $logId === null ? [] : [$logId],
            detail: $this->detail($detail),
            payload: $this->stored($data),
        );
    }
}
