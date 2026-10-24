<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * Mailgun (the JSON webhooks of the current API; the legacy form-encoded ones are not supported).
 *
 * Verification: `signature.signature` = hex HMAC-SHA256(signing key, timestamp . token), timestamp checked
 * against the tolerance. Correlation: `message.headers.message-id` (the id the Mailgun API transport returns)
 * and `user-variables.mail_log_id` (a `v:` variable, set by MetadataHeader).
 */
final class MailgunDriver extends AbstractDriver
{
    public function verify(Request $request): void
    {
        $key = $this->option('signing_key');
        $data = json_decode($request->getContent(), true);
        $signature = is_array($data) && is_array($data['signature'] ?? null) ? $data['signature'] : [];

        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;
        $given = $signature['signature'] ?? null;

        if ($key === '' || !is_string($token) || !is_string($given) || !(is_string($timestamp) || is_int($timestamp)) || !ctype_digit((string) $timestamp)) {
            throw InvalidWebhook::forbidden();
        }

        if (!hash_equals(hash_hmac('sha256', $timestamp.$token, $key), strtolower($given))) {
            throw InvalidWebhook::forbidden();
        }

        $this->assertFresh((int) $timestamp);
    }

    public function events(Request $request): array
    {
        $data = $this->json($request);
        $event = $data['event-data'] ?? null;

        if (!is_array($event) || !is_string($event['event'] ?? null)) {
            throw InvalidWebhook::malformed();
        }

        $status = is_array($event['delivery-status'] ?? null) ? $event['delivery-status'] : [];
        $detail = $status['description'] ?? $status['message'] ?? $event['reason'] ?? null;
        $name = $event['event'];

        [$type, $bounce] = match (true) {
            $name === 'delivered' => [DeliveryEventType::Delivered, null],
            $name === 'opened' => [DeliveryEventType::Opened, null],
            $name === 'clicked' => [DeliveryEventType::Clicked, null],
            $name === 'complained' => [DeliveryEventType::Complained, null],
            $name === 'rejected' => [DeliveryEventType::Rejected, null],
            // `failed` + permanent is a hard bounce; temporary ones are retried by Mailgun, so they are only "deferred".
            $name === 'failed' && ($event['severity'] ?? null) === 'permanent' => [DeliveryEventType::Bounced, BounceType::Hard],
            $name === 'failed' => [DeliveryEventType::Deferred, null],
            default => [null, null],
        };

        if ($type === null) {
            return [];
        }

        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $headers = is_array($message['headers'] ?? null) ? $message['headers'] : [];
        $variables = is_array($event['user-variables'] ?? null) ? $event['user-variables'] : [];
        $messageId = $this->messageId($headers['message-id'] ?? null);
        $logId = $this->logId($variables['mail_log_id'] ?? null);
        $recipient = is_string($event['recipient'] ?? null) ? strtolower(trim($event['recipient'])) : null;

        return [new DeliveryEvent(
            provider: $this->name,
            id: is_string($event['id'] ?? null) && $event['id'] !== '' ? $event['id'] : sha1($request->getContent()),
            type: $type,
            occurredAt: $this->time($event['timestamp'] ?? null),
            recipient: $recipient,
            bounce: $bounce,
            messageIds: $messageId === null ? [] : [$messageId],
            logIds: $logId === null ? [] : [$logId],
            detail: $this->detail($detail),
            payload: $this->stored($event),
        )];
    }
}
