<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        // The signature covers only timestamp + token, not the body: a captured pair would let anyone post any
        // event-data for the whole window. Each token is bound to the first body it came with; the same body
        // again (Mailgun retries after a 5xx) is fine, another body is a replay.
        $fingerprint = hash('sha256', $request->getContent());
        $key = 'filament-mail-log:mailgun-token:'.sha1($token);
        $window = max(60, (int) ($this->config['tolerance'] ?? config('filament-mail-log.webhooks.tolerance', 300)) * 2);

        if (!Cache::add($key, $fingerprint, $window) && !hash_equals((string) Cache::get($key), $fingerprint)) {
            throw InvalidWebhook::forbidden('The signature was already used.');
        }
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
        $reason = is_string($event['reason'] ?? null) ? $event['reason'] : '';

        [$type, $bounce] = match (true) {
            $name === 'delivered' => [DeliveryEventType::Delivered, null],
            $name === 'opened' => [DeliveryEventType::Opened, null],
            $name === 'clicked' => [DeliveryEventType::Clicked, null],
            $name === 'complained' => [DeliveryEventType::Complained, null],
            $name === 'rejected' => [DeliveryEventType::Rejected, null],
            // `failed` + permanent: only a real bounce is a dead address. `old` (retries exhausted after an outage),
            // `espblock`, `generic`, `suppress-unsubscribe`... say nothing about the address, so they are only "rejected".
            $name === 'failed' && ($event['severity'] ?? null) === 'permanent' && $reason === 'suppress-complaint' => [DeliveryEventType::Complained, null],
            $name === 'failed' && ($event['severity'] ?? null) === 'permanent' && in_array($reason, ['bounce', 'hardfail', 'suppress-bounce'], true) => [DeliveryEventType::Bounced, BounceType::Hard],
            $name === 'failed' && ($event['severity'] ?? null) === 'permanent' => [DeliveryEventType::Rejected, null],
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
