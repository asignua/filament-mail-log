<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Webhooks;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Models\MailSuppression;

/**
 * Payload shapes: Postmark "Webhooks" documentation (Delivery, Bounce, Spam complaint, Open, Click).
 */
class PostmarkWebhookTest extends WebhookTestCase
{
    private const string MESSAGE_ID = '883953f4-6105-42a2-a16a-77a8eac79483';

    protected function provider(): string
    {
        return 'postmark';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->enable('postmark', ['username' => 'hook', 'password' => 's3cret']);
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $user = 'hook', string $password = 's3cret'): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$password)];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function delivery(array $extra = []): string
    {
        return (string) json_encode($extra + [
            'RecordType' => 'Delivery',
            'MessageStream' => 'outbound',
            'ServerID' => 23,
            'MessageID' => self::MESSAGE_ID,
            'Recipient' => 'ann@example.test',
            'Tag' => 'welcome-email',
            'DeliveredAt' => '2026-10-20T16:33:54.9070259Z',
            'Details' => 'Test delivery webhook details',
            'Metadata' => ['example' => 'value'],
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function bounce(array $extra = []): string
    {
        return (string) json_encode($extra + [
            'RecordType' => 'Bounce',
            'ID' => 4323372036854775807,
            'Type' => 'HardBounce',
            'TypeCode' => 1,
            'MessageID' => self::MESSAGE_ID,
            'Details' => 'Test bounce details',
            'Email' => 'Ann@Example.test',
            'BouncedAt' => '2026-10-20T16:35:54.9070259Z',
            'Name' => 'Hard bounce',
            'Description' => 'The server was unable to deliver your message (ex: unknown user, mailbox not found).',
            'Metadata' => [],
        ]);
    }

    public function test_it_is_off_until_enabled(): void
    {
        config()->set('filament-mail-log.webhooks.providers.postmark.enabled', false);

        $this->hook($this->delivery(), $this->auth())->assertNotFound();
        $this->hook($this->delivery(), $this->auth(), 'nonsense')->assertNotFound();
    }

    public function test_missing_or_wrong_credentials_are_refused(): void
    {
        $this->log(['message_id' => self::MESSAGE_ID]);

        $this->hook($this->delivery())->assertForbidden();
        $this->hook($this->delivery(), $this->auth('hook', 'wrong'))->assertForbidden();
        $this->hook($this->delivery(), $this->auth('other', 's3cret'))->assertForbidden();
        $this->hook($this->delivery(), ['Authorization' => 'Bearer s3cret'])->assertForbidden();

        $this->assertCount(0, $this->events());
    }

    public function test_an_enabled_provider_without_a_secret_refuses_everything(): void
    {
        $this->enable('postmark', ['username' => '', 'password' => '']);

        $this->hook($this->delivery(), $this->auth('', ''))->assertForbidden();
    }

    public function test_a_delivery_is_matched_by_the_transport_message_id(): void
    {
        $log = $this->log(['message_id' => self::MESSAGE_ID]);

        $this->hook($this->delivery(), $this->auth())->assertOk()->assertJson(['received' => 1, 'matched' => 1]);

        $event = $this->events()->sole();
        $this->assertSame($log->getKey(), $event->mail_log_id);
        $this->assertSame(DeliveryEventType::Delivered, $event->type);
        $this->assertSame('postmark', $event->provider);
        $this->assertSame('ann@example.test', $event->recipient);
        $this->assertSame('2026-10-20 16:33:54', $event->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(DeliveryStatus::Delivered, $log->fresh()->delivery_status);
    }

    public function test_a_delivery_is_matched_by_the_metadata_when_the_message_id_is_unknown(): void
    {
        $log = $this->log();

        $this->hook($this->delivery(['MessageID' => 'unknown', 'Metadata' => ['mail_log_id' => strtolower($log->ulid)]]), $this->auth())
            ->assertOk()->assertJson(['matched' => 1]);

        $this->assertSame($log->getKey(), $this->events()->sole()->mail_log_id);
    }

    public function test_the_metadata_wins_over_a_message_id_that_belongs_to_another_row(): void
    {
        $other = $this->log(['message_id' => self::MESSAGE_ID]);
        $mine = $this->log();

        $this->hook($this->delivery(['Metadata' => ['mail_log_id' => $mine->ulid]]), $this->auth())->assertOk();

        $this->assertSame($mine->getKey(), $this->events()->sole()->mail_log_id);
        $this->assertNull($other->fresh()->delivery_status);
    }

    public function test_an_unmatched_event_is_acknowledged_and_not_stored(): void
    {
        $this->hook($this->delivery(), $this->auth())->assertOk()->assertJson(['received' => 1, 'matched' => 0]);

        $this->assertCount(0, $this->events());
    }

    public function test_a_hard_bounce_sets_the_status_and_suppresses_the_address(): void
    {
        $log = $this->log(['message_id' => self::MESSAGE_ID]);

        $this->hook($this->bounce(), $this->auth())->assertOk();

        $event = $this->events()->sole();
        $this->assertSame(DeliveryEventType::Bounced, $event->type);
        $this->assertSame(BounceType::Hard, $event->bounce);
        $this->assertSame('Test bounce details', $event->detail);
        $this->assertSame(DeliveryStatus::Bounced, $log->fresh()->delivery_status);

        $suppression = MailSuppression::query()->sole();
        $this->assertSame('ann@example.test', $suppression->email);
        $this->assertSame('hard_bounce', $suppression->reason->value);
        $this->assertSame('postmark', $suppression->provider);
        $this->assertSame($log->getKey(), $suppression->mail_log_id);
    }

    public function test_a_soft_bounce_does_not_suppress(): void
    {
        $log = $this->log(['message_id' => self::MESSAGE_ID]);

        $this->hook($this->bounce(['Type' => 'Transient', 'ID' => 7]), $this->auth())->assertOk();

        $this->assertSame(BounceType::Soft, $this->events()->sole()->bounce);
        $this->assertSame(DeliveryStatus::SoftBounced, $log->fresh()->delivery_status);
        $this->assertSame(0, MailSuppression::query()->count());
    }

    public function test_a_spam_complaint_suppresses_even_when_the_message_is_not_in_the_log(): void
    {
        $this->hook((string) json_encode([
            'RecordType' => 'SpamComplaint',
            'ID' => 42,
            'Type' => 'SpamComplaint',
            'MessageID' => 'not-ours',
            'Email' => 'zoe@example.test',
            'BouncedAt' => '2026-10-20T16:35:54Z',
            'Details' => 'Test spam complaint details',
        ]), $this->auth())->assertOk()->assertJson(['matched' => 0]);

        $this->assertSame('complaint', MailSuppression::query()->sole()->reason->value);
        $this->assertCount(0, $this->events());
    }

    public function test_open_and_click_are_recorded_and_unrelated_types_are_ignored(): void
    {
        $log = $this->log(['message_id' => self::MESSAGE_ID]);

        $this->hook((string) json_encode(['RecordType' => 'Open', 'MessageID' => self::MESSAGE_ID, 'Recipient' => 'ann@example.test', 'ReceivedAt' => '2026-10-20T17:00:00Z']), $this->auth())->assertOk();
        $this->hook((string) json_encode(['RecordType' => 'Click', 'MessageID' => self::MESSAGE_ID, 'Recipient' => 'ann@example.test', 'ReceivedAt' => '2026-10-20T17:01:00Z', 'OriginalLink' => 'https://example.test/a?token=abc123']), $this->auth())->assertOk();
        $this->hook((string) json_encode(['RecordType' => 'SubscriptionChange', 'MessageID' => self::MESSAGE_ID]), $this->auth())->assertOk()->assertJson(['received' => 0]);
        $this->hook($this->bounce(['Type' => 'Unsubscribe', 'ID' => 9]), $this->auth())->assertOk()->assertJson(['received' => 0]);

        $this->assertSame([DeliveryEventType::Opened, DeliveryEventType::Clicked], $this->events()->pluck('type')->all());
        $this->assertSame(DeliveryStatus::Clicked, $log->fresh()->delivery_status);
        $this->assertStringNotContainsString('abc123', (string) $this->events()->last()->detail);
    }

    public function test_a_redelivered_webhook_is_stored_once(): void
    {
        $log = $this->log(['message_id' => self::MESSAGE_ID]);

        foreach (range(1, 3) as $ignored) {
            $this->hook($this->bounce(), $this->auth())->assertOk();
            $this->hook($this->delivery(), $this->auth())->assertOk();
            $this->hook($this->delivery(), $this->auth())->assertOk();
        }

        $this->assertCount(2, $this->events());
        $this->assertSame(1, MailSuppression::query()->count());
        // The late "delivered" did not hide the bounce.
        $this->assertSame(DeliveryStatus::Bounced, $log->fresh()->delivery_status);
    }

    public function test_a_body_that_is_not_json_is_a_bad_request(): void
    {
        $this->hook('not json', $this->auth())->assertStatus(400);
    }
}
