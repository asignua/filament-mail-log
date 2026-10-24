<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Webhooks;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Models\MailSuppression;

/**
 * Payload shapes: SendGrid "Event Webhook Reference" sample events; signature scheme from "Getting Started with
 * the Event Webhook Security Features" (ECDSA P-256 over timestamp + raw body, base64 SPKI public key).
 */
class SendGridWebhookTest extends WebhookTestCase
{
    /** @var \OpenSSLAsymmetricKey */
    private $private;

    private string $publicKey;

    protected function provider(): string
    {
        return 'sendgrid';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        $this->private = $key;

        $pem = (string) openssl_pkey_get_details($key)['key'];
        $this->publicKey = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        $this->enable('sendgrid', ['public_key' => $this->publicKey]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function body(array $events): string
    {
        return (string) json_encode($events);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        openssl_sign($timestamp.$body, $signature, $this->private, OPENSSL_ALGO_SHA256);

        return [
            'X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature),
            'X-Twilio-Email-Event-Webhook-Timestamp' => $timestamp,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function event(string $event, array $extra = []): array
    {
        return $extra + [
            'email' => 'Example@Test.com',
            'timestamp' => 1513299569,
            'smtp-id' => '<14c5d75ce93.dfd.64b469@ismtpd-555>',
            'event' => $event,
            'category' => ['cat facts'],
            'sg_event_id' => 'sg_event_'.$event,
            'sg_message_id' => '14c5d75ce93.dfd.64b469.filter0001.16648.5515E0B88.0',
        ];
    }

    public function test_it_is_off_until_enabled(): void
    {
        config()->set('filament-mail-log.webhooks.providers.sendgrid.enabled', false);
        $body = $this->body([$this->event('delivered')]);

        $this->hook($body, $this->signed($body))->assertNotFound();
    }

    public function test_a_missing_wrong_tampered_or_stale_signature_is_refused(): void
    {
        $this->log(['message_id' => '14c5d75ce93']);
        $body = $this->body([$this->event('delivered')]);

        $this->hook($body)->assertForbidden();
        $this->hook($body, ['X-Twilio-Email-Event-Webhook-Signature' => base64_encode('junk'), 'X-Twilio-Email-Event-Webhook-Timestamp' => (string) time()])->assertForbidden();
        // Signed for another body.
        $this->hook($this->body([$this->event('bounce')]), $this->signed($body))->assertForbidden();
        // Signed an hour ago.
        $this->hook($body, $this->signed($body, time() - 3600))->assertForbidden();
        // Signed by another key.
        $other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $timestamp = (string) time();
        openssl_sign($timestamp.$body, $signature, $other, OPENSSL_ALGO_SHA256);
        $this->hook($body, ['X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature), 'X-Twilio-Email-Event-Webhook-Timestamp' => $timestamp])->assertForbidden();

        $this->assertCount(0, $this->events());
    }

    public function test_it_refuses_everything_without_a_key(): void
    {
        $body = $this->body([$this->event('delivered')]);
        $headers = $this->signed($body);
        $this->enable('sendgrid', ['public_key' => '']);

        $this->hook($body, $headers)->assertForbidden();
    }

    public function test_events_are_matched_by_the_message_id_prefix_or_the_smtp_id(): void
    {
        $byApiId = $this->log(['message_id' => '14c5d75ce93']);
        $bySmtpId = $this->log(['message_id' => '14c5d75ce93.dfd.64b469@ismtpd-555']);

        $body = $this->body([$this->event('delivered')]);
        $this->hook($body, $this->signed($body))->assertOk()->assertJson(['received' => 1, 'matched' => 1]);

        // The newest row with a matching id wins: the SMTP id row was created last.
        $this->assertSame($bySmtpId->getKey(), $this->events()->sole()->mail_log_id);
        $this->assertNull($byApiId->fresh()->delivery_status);
    }

    public function test_it_is_matched_by_the_custom_argument(): void
    {
        $log = $this->log();
        $body = $this->body([$this->event('open', ['mail_log_id' => $log->ulid, 'sg_message_id' => 'nope', 'smtp-id' => '<nope>'])]);

        $this->hook($body, $this->signed($body))->assertOk()->assertJson(['matched' => 1]);

        $this->assertSame(DeliveryStatus::Opened, $log->fresh()->delivery_status);
    }

    public function test_a_batch_is_processed_and_untracked_events_are_left_out(): void
    {
        $log = $this->log(['message_id' => '14c5d75ce93']);
        $body = $this->body([
            $this->event('processed'),
            $this->event('delivered'),
            $this->event('click', ['url' => 'http://example.com/?sig=secretvalue']),
            $this->event('unsubscribe'),
        ]);

        $this->hook($body, $this->signed($body))->assertOk()->assertJson(['received' => 2, 'matched' => 2]);

        $this->assertSame([DeliveryEventType::Delivered, DeliveryEventType::Clicked], $this->events()->pluck('type')->all());
        $this->assertSame(DeliveryStatus::Clicked, $log->fresh()->delivery_status);
        $this->assertStringNotContainsString('secretvalue', (string) json_encode($this->events()->last()->payload));
    }

    public function test_a_bounce_is_hard_unless_it_is_blocked_or_a_4xx_status(): void
    {
        $hard = $this->log(['message_id' => '14c5d75ce93']);
        $body = $this->body([$this->event('bounce', ['reason' => '500 unknown recipient', 'status' => '5.0.0', 'type' => 'bounce'])]);
        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertSame(BounceType::Hard, $this->events()->sole()->bounce);
        $this->assertSame('500 unknown recipient', $this->events()->sole()->detail);
        $this->assertSame(DeliveryStatus::Bounced, $hard->fresh()->delivery_status);
        $this->assertSame('example@test.com', MailSuppression::query()->sole()->email);

        $soft = $this->log(['message_id' => 'soft-one']);
        $body = $this->body([$this->event('bounce', ['sg_event_id' => 'e2', 'sg_message_id' => 'soft-one.f', 'smtp-id' => '<x>', 'email' => 'b@test.com', 'status' => '4.2.2', 'type' => 'blocked'])]);
        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertSame(DeliveryStatus::SoftBounced, $soft->fresh()->delivery_status);
        $this->assertSame(1, MailSuppression::query()->count());
    }

    public function test_dropped_deferred_and_spamreport(): void
    {
        $log = $this->log(['message_id' => '14c5d75ce93']);
        $body = $this->body([
            $this->event('deferred', ['response' => '400 try later']),
            $this->event('dropped', ['reason' => 'Bounced Address']),
            $this->event('spamreport'),
        ]);

        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertSame([DeliveryEventType::Deferred, DeliveryEventType::Rejected, DeliveryEventType::Complained], $this->events()->pluck('type')->all());
        $this->assertSame(DeliveryStatus::Complained, $log->fresh()->delivery_status);
    }

    public function test_a_resent_batch_is_stored_once(): void
    {
        $this->log(['message_id' => '14c5d75ce93']);
        $body = $this->body([$this->event('delivered'), $this->event('open')]);

        $this->hook($body, $this->signed($body))->assertOk();
        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertCount(2, $this->events());
    }
}
