<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Amazon SES through an SNS HTTP(S) subscription (configuration set event destination, or the identity
 * feedback notifications; both the `eventType` and the legacy `notificationType` shapes are read).
 *
 * Verification, as AWS documents it for SNS: the signing certificate is fetched from `SigningCertURL` (https,
 * host `sns.<region>.amazonaws.com`, a `.pem`; cached a day), the canonical string of the message is verified
 * with SHA1 (SignatureVersion 1) or SHA256 (2), the `TopicArn` must be one of `topic_arns` (a valid signature
 * only proves it came from SOME SNS topic), and `Timestamp` must be within `tolerance`.
 *
 * A `SubscriptionConfirmation` is answered by GET-ing its `SubscribeURL` (same host rule), when `auto_confirm`.
 *
 * Correlation: `mail.messageId` (the id the SES API transport returns), `mail.commonHeaders.messageId`, and
 * the log id in `mail.tags.mail_log_id` or the echoed `X-Mail-Log-Id` / `X-Metadata-mail_log_id` header
 * (headers are present only if "include original headers" is on in SES).
 */
final class SesDriver extends AbstractDriver
{
    private const string HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/i';

    /** Keys that are signed, in the order AWS defines, per message type. */
    private const array SIGNED = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    public function verify(Request $request): void
    {
        $topics = $this->config['topic_arns'] ?? [];

        if (!is_array($topics) || $topics === []) {
            throw InvalidWebhook::forbidden();
        }

        $message = $this->message($request);
        $type = $message['Type'] ?? null;

        if (!is_string($type) || !isset(self::SIGNED[$type])) {
            throw InvalidWebhook::forbidden();
        }

        if (!is_string($message['TopicArn'] ?? null) || !in_array($message['TopicArn'], $topics, true)) {
            throw InvalidWebhook::forbidden();
        }

        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        $algorithm = match ((string) ($message['SignatureVersion'] ?? '')) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };

        if ($signature === false || $signature === '' || $algorithm === null) {
            throw InvalidWebhook::forbidden();
        }

        $canonical = '';

        foreach (self::SIGNED[$type] as $key) {
            if (isset($message[$key]) && is_string($message[$key])) {
                $canonical .= $key."\n".$message[$key]."\n";
            }
        }

        $certificate = $this->certificate((string) ($message['SigningCertURL'] ?? ''));

        if (openssl_verify($canonical, $signature, $certificate, $algorithm) !== 1) {
            throw InvalidWebhook::forbidden();
        }

        $this->assertFresh((int) strtotime((string) ($message['Timestamp'] ?? '')));
    }

    public function events(Request $request): array
    {
        $message = $this->message($request);

        switch ($message['Type'] ?? null) {
            case 'SubscriptionConfirmation':
                $this->confirm((string) ($message['SubscribeURL'] ?? ''));

                return [];
            case 'Notification':
                break;
            default:
                return [];
        }

        $body = json_decode((string) ($message['Message'] ?? ''), true);

        if (!is_array($body)) {
            // SNS also carries plain-text messages (a test publish from the console); nothing to track.
            return [];
        }

        return $this->notification($body, (string) ($message['MessageId'] ?? ''));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<DeliveryEvent>
     */
    private function notification(array $body, string $snsId): array
    {
        $name = $body['eventType'] ?? $body['notificationType'] ?? null;
        $mail = is_array($body['mail'] ?? null) ? $body['mail'] : [];
        $stored = $this->stored($body);
        $ids = $this->messageIds($mail);
        $logIds = $this->logIds($mail);
        $destination = array_values(array_filter(is_array($mail['destination'] ?? null) ? $mail['destination'] : [], 'is_string'));

        $make = function (DeliveryEventType $type, ?string $recipient, mixed $at, ?BounceType $bounce = null, mixed $detail = null) use ($snsId, $ids, $logIds, $stored): DeliveryEvent {
            return new DeliveryEvent(
                provider: $this->name,
                id: $snsId.'#'.$type->value.'#'.strtolower((string) $recipient),
                type: $type,
                occurredAt: $this->time($at),
                recipient: $recipient === null ? null : strtolower(trim($recipient)),
                bounce: $bounce,
                messageIds: $ids,
                logIds: $logIds,
                detail: $this->detail($detail),
                payload: $stored,
            );
        };

        $events = [];

        switch ($name) {
            case 'Delivery':
                $info = is_array($body['delivery'] ?? null) ? $body['delivery'] : [];

                foreach ($this->addresses($info['recipients'] ?? null, $destination) as $to) {
                    $events[] = $make(DeliveryEventType::Delivered, $to, $info['timestamp'] ?? null, null, $info['smtpResponse'] ?? null);
                }
                break;
            case 'Bounce':
                $info = is_array($body['bounce'] ?? null) ? $body['bounce'] : [];
                // Permanent = the address is wrong; Transient / Undetermined may work later.
                $bounce = ($info['bounceType'] ?? null) === 'Permanent' ? BounceType::Hard : BounceType::Soft;

                foreach (is_array($info['bouncedRecipients'] ?? null) ? $info['bouncedRecipients'] : [] as $row) {
                    if (is_array($row) && is_string($row['emailAddress'] ?? null)) {
                        $events[] = $make(DeliveryEventType::Bounced, $row['emailAddress'], $info['timestamp'] ?? null, $bounce, $row['diagnosticCode'] ?? $info['bounceSubType'] ?? null);
                    }
                }
                break;
            case 'Complaint':
                $info = is_array($body['complaint'] ?? null) ? $body['complaint'] : [];

                foreach (is_array($info['complainedRecipients'] ?? null) ? $info['complainedRecipients'] : [] as $row) {
                    if (is_array($row) && is_string($row['emailAddress'] ?? null)) {
                        $events[] = $make(DeliveryEventType::Complained, $row['emailAddress'], $info['timestamp'] ?? null, null, $info['complaintFeedbackType'] ?? null);
                    }
                }
                break;
            case 'Reject':
                $info = is_array($body['reject'] ?? null) ? $body['reject'] : [];

                foreach ($this->addresses(null, $destination) as $to) {
                    $events[] = $make(DeliveryEventType::Rejected, $to, $mail['timestamp'] ?? null, null, $info['reason'] ?? null);
                }
                break;
            case 'DeliveryDelay':
                $info = is_array($body['deliveryDelay'] ?? null) ? $body['deliveryDelay'] : [];

                foreach (is_array($info['delayedRecipients'] ?? null) ? $info['delayedRecipients'] : [] as $row) {
                    if (is_array($row) && is_string($row['emailAddress'] ?? null)) {
                        $events[] = $make(DeliveryEventType::Deferred, $row['emailAddress'], $info['timestamp'] ?? null, null, $row['diagnosticCode'] ?? $info['delayType'] ?? null);
                    }
                }
                break;
            case 'Open':
                $info = is_array($body['open'] ?? null) ? $body['open'] : [];
                $events[] = $make(DeliveryEventType::Opened, $destination[0] ?? null, $info['timestamp'] ?? null);
                break;
            case 'Click':
                $info = is_array($body['click'] ?? null) ? $body['click'] : [];
                $events[] = $make(DeliveryEventType::Clicked, $destination[0] ?? null, $info['timestamp'] ?? null, null, $info['link'] ?? null);
                break;
        }

        return $events;
    }

    /**
     * @param array<array-key, mixed> $mail
     *
     * @return list<string>
     */
    private function messageIds(array $mail): array
    {
        $common = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];
        $ids = [$this->messageId($mail['messageId'] ?? null), $this->messageId($common['messageId'] ?? null)];

        foreach (is_array($mail['headers'] ?? null) ? $mail['headers'] : [] as $header) {
            if (is_array($header) && strtolower((string) ($header['name'] ?? '')) === 'message-id') {
                $ids[] = $this->messageId($header['value'] ?? null);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param array<array-key, mixed> $mail
     *
     * @return list<string>
     */
    private function logIds(array $mail): array
    {
        $ids = [];
        $tags = is_array($mail['tags'] ?? null) ? $mail['tags'] : [];
        $ids[] = $this->logId($tags['mail_log_id'] ?? null);

        foreach (is_array($mail['headers'] ?? null) ? $mail['headers'] : [] as $header) {
            if (is_array($header) && in_array(strtolower((string) ($header['name'] ?? '')), ['x-mail-log-id', 'x-metadata-mail_log_id'], true)) {
                $ids[] = $this->logId($header['value'] ?? null);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param list<string> $fallback
     *
     * @return list<string>
     */
    private function addresses(mixed $listed, array $fallback): array
    {
        $list = array_values(array_filter(is_array($listed) ? $listed : [], 'is_string'));

        return $list === [] ? $fallback : $list;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function message(Request $request): array
    {
        // SNS posts JSON with `Content-Type: text/plain`.
        $message = json_decode($request->getContent(), true);

        if (!is_array($message)) {
            throw InvalidWebhook::forbidden();
        }

        return $message;
    }

    private function certificate(string $url): string
    {
        $this->assertAwsUrl($url, true);

        try {
            return (string) Cache::remember(
                'filament-mail-log:sns-certificate:'.sha1($url),
                now()->addDay(),
                static fn (): string => Http::timeout(5)->get($url)->throw()->body(),
            );
        } catch (ConnectionException|RequestException) {
            throw InvalidWebhook::unavailable('The signing certificate could not be fetched.');
        }
    }

    private function confirm(string $url): void
    {
        if (!(bool) ($this->config['auto_confirm'] ?? true)) {
            return;
        }

        $this->assertAwsUrl($url, false);

        try {
            Http::timeout(5)->get($url)->throw();
        } catch (ConnectionException|RequestException) {
            throw InvalidWebhook::unavailable('The subscription could not be confirmed.');
        }
    }

    /**
     * Only https URLs on `sns.<region>.amazonaws.com` are ever fetched: the URL comes from the request.
     */
    private function assertAwsUrl(string $url, bool $pem): void
    {
        $parts = parse_url($url);

        $ok = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && preg_match(self::HOST, $parts['host']) === 1
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && (!$pem || str_ends_with(strtolower($parts['path'] ?? ''), '.pem'));

        if (!$ok) {
            throw InvalidWebhook::forbidden();
        }
    }
}
