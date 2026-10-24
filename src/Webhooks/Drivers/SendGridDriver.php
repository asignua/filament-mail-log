<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * SendGrid Event Webhook (signed). The body is a JSON array of events.
 *
 * Verification: `X-Twilio-Email-Event-Webhook-Signature` is a base64 ECDSA (P-256, SHA-256) signature of
 * `{X-Twilio-Email-Event-Webhook-Timestamp}{raw body}`, checked against the verification key of the webhook
 * (base64 SPKI as SendGrid shows it). The timestamp is checked against the tolerance.
 *
 * Correlation: `sg_message_id` (its first dot-separated part is the id the SendGrid API transport returns),
 * `smtp-id` (the Message-ID header) and the `custom_args.mail_log_id` that SendGrid merges into the event.
 */
final class SendGridDriver extends AbstractDriver
{
    public function verify(Request $request): void
    {
        $key = $this->option('public_key');
        $signature = (string) $request->header('X-Twilio-Email-Event-Webhook-Signature', '');
        $timestamp = (string) $request->header('X-Twilio-Email-Event-Webhook-Timestamp', '');

        if ($key === '' || $signature === '' || !ctype_digit($timestamp)) {
            throw InvalidWebhook::forbidden();
        }

        $pem = str_contains($key, 'BEGIN PUBLIC KEY')
            ? $key
            : "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----\n";
        $decoded = base64_decode($signature, true);

        if ($decoded === false || openssl_verify($timestamp.$request->getContent(), $decoded, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw InvalidWebhook::forbidden();
        }

        $this->assertFresh((int) $timestamp);
    }

    public function events(Request $request): array
    {
        $rows = $this->json($request);
        $events = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['event'] ?? null)) {
                continue;
            }

            $bounce = null;
            $detail = $row['reason'] ?? null;

            switch ($row['event']) {
                case 'delivered':
                    $type = DeliveryEventType::Delivered;
                    break;
                case 'open':
                    $type = DeliveryEventType::Opened;
                    break;
                case 'click':
                    $type = DeliveryEventType::Clicked;
                    $detail = $row['url'] ?? null;
                    break;
                case 'spamreport':
                    $type = DeliveryEventType::Complained;
                    break;
                case 'deferred':
                    $type = DeliveryEventType::Deferred;
                    break;
                case 'dropped':
                    $type = DeliveryEventType::Rejected;
                    break;
                case 'bounce':
                    $type = DeliveryEventType::Bounced;
                    // `blocked` (the receiving server refused it) and 4.x.x codes can be retried; the rest is final.
                    $soft = ($row['type'] ?? null) === 'blocked' || (is_string($row['status'] ?? null) && str_starts_with($row['status'], '4'));
                    $bounce = $soft ? BounceType::Soft : BounceType::Hard;
                    break;
                default:
                    continue 2;
            }

            $sg = $this->messageId(is_string($row['sg_message_id'] ?? null) ? explode('.', $row['sg_message_id'])[0] : null);
            $smtp = $this->messageId($row['smtp-id'] ?? null);
            $logId = $this->logId($row['mail_log_id'] ?? null);

            $events[] = new DeliveryEvent(
                provider: $this->name,
                id: is_string($row['sg_event_id'] ?? null) && $row['sg_event_id'] !== '' ? $row['sg_event_id'] : sha1((string) json_encode($row)),
                type: $type,
                occurredAt: $this->time($row['timestamp'] ?? null),
                recipient: is_string($row['email'] ?? null) ? strtolower(trim($row['email'])) : null,
                bounce: $bounce,
                messageIds: array_values(array_filter([$sg, $smtp])),
                logIds: $logId === null ? [] : [$logId],
                detail: $this->detail($detail),
                payload: $this->stored($row),
            );
        }

        return $events;
    }
}
