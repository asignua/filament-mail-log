<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Webhooks;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Models\MailSuppression;

/**
 * Payload shapes: Resend "Webhook event types" documentation (email.delivered, email.bounced, ...); the signature
 * scheme is Svix's (id.timestamp.body, HMAC-SHA256, base64, "v1," prefix).
 */
class ResendWebhookTest extends WebhookTestCase
{
    private const string EMAIL_ID = '56761188-7520-42d8-8898-ff6fc54ce618';

    private string $secret;

    protected function provider(): string
    {
        return 'resend';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'whsec_'.base64_encode('0123456789abcdef0123456789abcdef');
        $this->enable('resend', ['secret' => $this->secret]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function body(string $type, array $data = []): string
    {
        return (string) json_encode([
            'type' => $type,
            'created_at' => '2026-10-22T23:41:12.126Z',
            'data' => $data + [
                'created_at' => '2026-10-22T23:41:11.894719+00:00',
                'email_id' => self::EMAIL_ID,
                'from' => 'Acme <onboarding@resend.dev>',
                'to' => ['Delivered@Resend.dev'],
                'subject' => 'Sending this example',
                'tags' => ['category' => 'confirm_email'],
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $body, string $id = 'msg_2KWPBgLlAfxdpx2AI54pPJ85f4W', ?int $timestamp = null, ?string $secret = null): array
    {
        $timestamp ??= time();
        $key = base64_decode(substr($secret ?? $this->secret, 6), true);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, (string) $key, true));

        return ['svix-id' => $id, 'svix-timestamp' => (string) $timestamp, 'svix-signature' => 'v1,'.$signature];
    }

    public function test_it_is_off_until_enabled(): void
    {
        config()->set('filament-mail-log.webhooks.providers.resend.enabled', false);
        $body = $this->body('email.delivered');

        $this->hook($body, $this->signed($body))->assertNotFound();
    }

    public function test_a_missing_wrong_or_stale_signature_is_refused(): void
    {
        $this->log(['message_id' => self::EMAIL_ID]);
        $body = $this->body('email.delivered');

        $this->hook($body)->assertForbidden();
        $this->hook($body, $this->signed($body, secret: 'whsec_'.base64_encode('another-secret-another-secret!!')))->assertForbidden();
        $this->hook($body.' ', $this->signed($body))->assertForbidden();
        $this->hook($body, $this->signed($body, timestamp: time() - 3600))->assertForbidden();
        $this->hook($body, ['svix-id' => 'x', 'svix-timestamp' => (string) time(), 'svix-signature' => 'v2,abc'])->assertForbidden();

        $this->assertCount(0, $this->events());
    }

    public function test_any_valid_signature_of_several_is_enough(): void
    {
        $log = $this->log(['message_id' => self::EMAIL_ID]);
        $body = $this->body('email.delivered');
        $headers = $this->signed($body);
        $headers['svix-signature'] = 'v1,'.base64_encode('stale-old-signature').' '.$headers['svix-signature'];

        $this->hook($body, $headers)->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $log->fresh()->delivery_status);
    }

    public function test_it_refuses_everything_without_a_secret(): void
    {
        $body = $this->body('email.delivered');
        $headers = $this->signed($body);
        $this->enable('resend', ['secret' => '']);

        $this->hook($body, $headers)->assertForbidden();
    }

    public function test_delivered_is_matched_by_the_email_id(): void
    {
        $log = $this->log(['message_id' => self::EMAIL_ID]);
        $body = $this->body('email.delivered');

        $this->hook($body, $this->signed($body))->assertOk()->assertJson(['matched' => 1]);

        $event = $this->events()->sole();
        $this->assertSame($log->getKey(), $event->mail_log_id);
        $this->assertSame('delivered@resend.dev', $event->recipient);
        $this->assertSame('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W', $event->provider_event_id);
    }

    public function test_it_is_matched_by_the_tag_set_from_the_metadata_header(): void
    {
        $log = $this->log();
        $body = $this->body('email.delivered', ['email_id' => 'unknown', 'tags' => ['mail_log_id' => $log->ulid]]);

        $this->hook($body, $this->signed($body))->assertOk()->assertJson(['matched' => 1]);

        $this->assertSame($log->getKey(), $this->events()->sole()->mail_log_id);
    }

    public function test_a_permanent_bounce_suppresses_and_a_transient_one_does_not(): void
    {
        $log = $this->log(['message_id' => self::EMAIL_ID]);

        $hard = $this->body('email.bounced', ['bounce' => ['message' => 'Suppressed: recent hard bounces.', 'subType' => 'Suppressed', 'type' => 'Permanent']]);
        $this->hook($hard, $this->signed($hard, 'msg_hard'))->assertOk();

        $this->assertSame(BounceType::Hard, $this->events()->sole()->bounce);
        $this->assertSame('Suppressed: recent hard bounces.', $this->events()->sole()->detail);
        $this->assertSame(DeliveryStatus::Bounced, $log->fresh()->delivery_status);
        $this->assertSame('delivered@resend.dev', MailSuppression::query()->sole()->email);

        $other = $this->log(['message_id' => 'other-id']);
        $soft = $this->body('email.bounced', ['email_id' => 'other-id', 'to' => ['soft@example.test'], 'bounce' => ['type' => 'Transient', 'subType' => 'MailboxFull', 'message' => 'Mailbox full']]);
        $this->hook($soft, $this->signed($soft, 'msg_soft'))->assertOk();

        $this->assertSame(DeliveryStatus::SoftBounced, $other->fresh()->delivery_status);
        $this->assertSame(1, MailSuppression::query()->count());
    }

    public function test_other_event_types(): void
    {
        $log = $this->log(['message_id' => self::EMAIL_ID]);
        $types = [
            'email.opened' => DeliveryEventType::Opened,
            'email.clicked' => DeliveryEventType::Clicked,
            'email.complained' => DeliveryEventType::Complained,
            'email.delivery_delayed' => DeliveryEventType::Deferred,
            'email.failed' => DeliveryEventType::Rejected,
        ];

        foreach ($types as $name => $type) {
            $body = $this->body($name);
            $this->hook($body, $this->signed($body, 'msg_'.$name))->assertOk();
        }

        $this->assertSame(array_values($types), $this->events()->pluck('type')->all());
        $this->assertSame(DeliveryStatus::Complained, $log->fresh()->delivery_status);

        foreach (['email.sent', 'email.scheduled', 'email.received', 'contact.created'] as $name) {
            $body = $this->body($name);
            $this->hook($body, $this->signed($body, 'msg_'.$name))->assertOk()->assertJson(['received' => 0]);
        }
    }

    public function test_a_retry_with_the_same_svix_id_is_stored_once(): void
    {
        $this->log(['message_id' => self::EMAIL_ID]);
        $body = $this->body('email.delivered');

        $this->hook($body, $this->signed($body))->assertOk();
        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertCount(1, $this->events());
    }

    public function test_one_event_per_recipient(): void
    {
        $this->log(['message_id' => self::EMAIL_ID]);
        $body = $this->body('email.delivered', ['to' => ['a@example.test', 'b@example.test']]);

        $this->hook($body, $this->signed($body))->assertOk();

        $this->assertSame(['a@example.test', 'b@example.test'], $this->events()->pluck('recipient')->all());
    }
}
