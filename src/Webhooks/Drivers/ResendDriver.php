<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * Resend (webhooks are delivered and signed by Svix).
 *
 * Verification: `svix-signature` holds one or more `v1,<base64>` entries; each is base64 HMAC-SHA256 of
 * `{svix-id}.{svix-timestamp}.{body}` with the base64-decoded part of the `whsec_...` secret. The timestamp is
 * checked against the tolerance. Correlation: `data.email_id` (the id the Resend API transport returns) and
 * `data.tags.mail_log_id`. The Svix id identifies the delivery, so it is the idempotency key (a retry reuses it).
 */
final class ResendDriver extends AbstractDriver
{
    public function verify(Request $request): void
    {
        $secret = $this->option('secret');
        $id = (string) $request->header('svix-id', '');
        $timestamp = (string) $request->header('svix-timestamp', '');
        $signatures = (string) $request->header('svix-signature', '');

        if ($secret === '' || $id === '' || !ctype_digit($timestamp) || $signatures === '') {
            throw InvalidWebhook::forbidden();
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false || $key === '') {
            throw InvalidWebhook::forbidden();
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));

        $valid = false;

        foreach (explode(' ', $signatures) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                $valid = true;
            }
        }

        if (!$valid) {
            throw InvalidWebhook::forbidden();
        }

        $this->assertFresh((int) $timestamp);
    }

    public function events(Request $request): array
    {
        $payload = $this->json($request);
        $name = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $bounce = null;
        $detail = null;

        switch ($name) {
            case 'email.delivered':
                $type = DeliveryEventType::Delivered;
                break;
            case 'email.opened':
                $type = DeliveryEventType::Opened;
                break;
            case 'email.clicked':
                $type = DeliveryEventType::Clicked;
                $detail = is_array($data['click'] ?? null) ? ($data['click']['link'] ?? null) : null;
                break;
            case 'email.complained':
                $type = DeliveryEventType::Complained;
                break;
            case 'email.delivery_delayed':
                $type = DeliveryEventType::Deferred;
                break;
            case 'email.failed':
            case 'email.suppressed':
                $type = DeliveryEventType::Rejected;
                $detail = is_array($data['failed'] ?? null) ? ($data['failed']['reason'] ?? null) : null;
                break;
            case 'email.bounced':
                $type = DeliveryEventType::Bounced;
                $info = is_array($data['bounce'] ?? null) ? $data['bounce'] : [];
                // Permanent = the address is wrong; Transient / Undetermined may work later.
                $bounce = ($info['type'] ?? null) === 'Permanent' ? BounceType::Hard : BounceType::Soft;
                $detail = $info['message'] ?? null;
                break;
            default:
                return [];
        }

        $svixId = (string) $request->header('svix-id', '');
        $emailId = $this->messageId($data['email_id'] ?? null);
        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $logId = $this->logId($tags['mail_log_id'] ?? null);
        $recipients = array_values(array_filter(
            is_array($data['to'] ?? null) ? $data['to'] : [],
            static fn (mixed $to): bool => is_string($to) && $to !== '',
        ));
        $recipients = $recipients === [] ? [null] : $recipients;
        $stored = $this->stored($payload);
        $events = [];

        foreach ($recipients as $to) {
            $events[] = new DeliveryEvent(
                provider: $this->name,
                id: count($recipients) > 1 ? $svixId.'#'.$to : $svixId,
                type: $type,
                occurredAt: $this->time($payload['created_at'] ?? $data['created_at'] ?? null),
                recipient: $to === null ? null : strtolower(trim($to)),
                bounce: $bounce,
                messageIds: $emailId === null ? [] : [$emailId],
                logIds: $logId === null ? [] : [$logId],
                detail: $this->detail($detail),
                payload: $stored,
            );
        }

        return $events;
    }
}
