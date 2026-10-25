<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Webhooks;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Models\MailSuppression;

/**
 * Payload shapes: Mailgun "Webhooks" documentation (event-data of delivered / failed, signature object).
 */
class MailgunWebhookTest extends WebhookTestCase
{
    private const string KEY = 'key-0123456789abcdef';

    protected function provider(): string
    {
        return 'mailgun';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->enable('mailgun', ['signing_key' => self::KEY]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function body(array $event, ?int $timestamp = null, ?string $key = null, ?string $token = null): string
    {
        $timestamp ??= time();
        $token ??= bin2hex(random_bytes(20));

        return (string) json_encode([
            'signature' => [
                'timestamp' => (string) $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp.$token, $key ?? self::KEY),
            ],
            'event-data' => $event,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function delivered(array $extra = []): array
    {
        return $extra + [
            'event' => 'delivered',
            'timestamp' => 1529692198.641658,
            'id' => 'CPgfbmQMTCKtHW6uIWtuVe',
            'tags' => ['my_tag_1'],
            'method' => 'smtp',
            'log-level' => 'info',
            'message' => ['headers' => [
                'to' => 'Alice <alice@example.org>',
                'message-id' => '20180622182958.1.48906CB188F1A454@example.org',
                'from' => 'Bob <bob@sandbox.example.org>',
                'subject' => 'Test deliver webhook',
            ], 'attachments' => [], 'size' => 18867],
            'recipient' => 'Alice@Example.org',
            'user-variables' => ['my_var_1' => 'Mailgun Variable #1'],
            'delivery-status' => ['tls' => true, 'code' => 250, 'description' => '', 'message' => 'OK', 'attempt-no' => 1],
        ];
    }

    public function test_it_is_off_until_enabled(): void
    {
        config()->set('filament-mail-log.webhooks.providers.mailgun.enabled', false);

        $this->hook($this->body($this->delivered()))->assertNotFound();
    }

    public function test_a_wrong_missing_or_stale_signature_is_refused(): void
    {
        $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        $this->hook($this->body($this->delivered(), null, 'another-key'))->assertForbidden();
        $this->hook((string) json_encode(['event-data' => $this->delivered()]))->assertForbidden();
        // A correctly signed request that is ten minutes old: replay protection.
        $this->hook($this->body($this->delivered(), time() - 600))->assertForbidden();

        $this->assertCount(0, $this->events());
    }

    public function test_it_refuses_everything_without_a_signing_key(): void
    {
        $this->enable('mailgun', ['signing_key' => '']);

        $this->hook($this->body($this->delivered(), null, ''))->assertForbidden();
    }

    public function test_delivered_is_matched_by_message_id_with_or_without_brackets(): void
    {
        $log = $this->log(['message_id' => '<20180622182958.1.48906CB188F1A454@example.org>']);

        $this->hook($this->body($this->delivered()))->assertOk()->assertJson(['matched' => 1]);

        $this->assertSame($log->getKey(), $this->events()->sole()->mail_log_id);
        $this->assertSame(DeliveryStatus::Delivered, $log->fresh()->delivery_status);
        $this->assertSame('alice@example.org', $this->events()->sole()->recipient);
    }

    public function test_it_is_matched_by_the_user_variable_set_from_the_metadata_header(): void
    {
        $log = $this->log();

        $this->hook($this->body($this->delivered(['user-variables' => ['mail_log_id' => $log->ulid]])))->assertOk();

        $this->assertSame($log->getKey(), $this->events()->sole()->mail_log_id);
    }

    public function test_a_permanent_failure_is_a_hard_bounce_and_suppresses(): void
    {
        $log = $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        $this->hook($this->body($this->delivered([
            'event' => 'failed',
            'id' => 'evt-failed-1',
            'severity' => 'permanent',
            'reason' => 'bounce',
            'delivery-status' => ['code' => 550, 'message' => '', 'description' => 'Not delivering to previously bounced address'],
        ])))->assertOk();

        $event = $this->events()->sole();
        $this->assertSame(DeliveryEventType::Bounced, $event->type);
        $this->assertSame(BounceType::Hard, $event->bounce);
        $this->assertSame('Not delivering to previously bounced address', $event->detail);
        $this->assertSame(DeliveryStatus::Bounced, $log->fresh()->delivery_status);
        $this->assertSame('alice@example.org', MailSuppression::query()->sole()->email);
    }

    public function test_a_temporary_failure_is_only_deferred(): void
    {
        $log = $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        $this->hook($this->body($this->delivered(['event' => 'failed', 'id' => 'evt-failed-2', 'severity' => 'temporary'])))->assertOk();

        $this->assertSame(DeliveryStatus::Deferred, $log->fresh()->delivery_status);
        $this->assertSame(0, MailSuppression::query()->count());
    }

    public function test_complained_opened_clicked_rejected_and_ignored_events(): void
    {
        $log = $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        foreach (['opened', 'clicked', 'rejected', 'complained', 'accepted', 'stored', 'unsubscribed'] as $i => $name) {
            $this->hook($this->body($this->delivered(['event' => $name, 'id' => 'evt-'.$i])))->assertOk();
        }

        $this->assertSame(
            [DeliveryEventType::Opened, DeliveryEventType::Clicked, DeliveryEventType::Rejected, DeliveryEventType::Complained],
            $this->events()->pluck('type')->all(),
        );
        $this->assertSame(DeliveryStatus::Complained, $log->fresh()->delivery_status);
        $this->assertSame('complaint', MailSuppression::query()->sole()->reason->value);
    }

    public function test_a_duplicate_event_id_is_stored_once(): void
    {
        $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        $this->hook($this->body($this->delivered(), null, null, 'token-one'))->assertOk();
        $this->hook($this->body($this->delivered(), null, null, 'token-two'))->assertOk();

        $this->assertCount(1, $this->events());
    }

    public function test_a_seen_signature_cannot_be_reused_with_another_body(): void
    {
        $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);
        $genuine = $this->body($this->delivered(), null, null, 'one-token');

        $this->hook($genuine)->assertOk();
        // The signature covers only timestamp + token: the same pair with other event-data is a replay.
        $forged = json_decode($genuine, true);
        $forged['event-data'] = $this->delivered(['event' => 'complained', 'id' => 'forged']);
        $this->hook((string) json_encode($forged))->assertForbidden();

        // A retry of the very same body (Mailgun retries after a 5xx) is still welcome.
        $this->hook($genuine)->assertOk();
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(0, MailSuppression::query()->count());
    }

    public function test_only_real_bounces_suppress(): void
    {
        $log = $this->log(['message_id' => '20180622182958.1.48906CB188F1A454@example.org']);

        foreach (['old', 'suppress-unsubscribe', 'espblock', 'generic', 'unknown-reason'] as $i => $reason) {
            $this->hook($this->body($this->delivered(['event' => 'failed', 'id' => 'r'.$i, 'severity' => 'permanent', 'reason' => $reason])))->assertOk();
        }

        $this->assertSame(array_fill(0, 5, DeliveryEventType::Rejected), $this->events()->pluck('type')->all());
        $this->assertSame(0, MailSuppression::query()->count());
        $this->assertSame(DeliveryStatus::Rejected, $log->fresh()->delivery_status);

        foreach (['bounce', 'hardfail', 'suppress-bounce'] as $i => $reason) {
            $this->hook($this->body($this->delivered(['event' => 'failed', 'id' => 'h'.$i, 'severity' => 'permanent', 'reason' => $reason, 'recipient' => 'h'.$i.'@example.org'])))->assertOk();
        }

        $this->assertSame(3, MailSuppression::query()->count());
        $this->assertSame(DeliveryStatus::Bounced, $log->fresh()->delivery_status);

        $this->hook($this->body($this->delivered(['event' => 'failed', 'id' => 'c', 'severity' => 'permanent', 'reason' => 'suppress-complaint', 'recipient' => 'c@example.org'])))->assertOk();
        $this->assertSame('complaint', MailSuppression::query()->where('email', 'c@example.org')->sole()->reason->value);
    }
}
