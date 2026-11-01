<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Mail\BounceAlertMail;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\BounceAlerts;
use Asignua\FilamentMailLog\Support\DeliveryTracker;
use Asignua\FilamentMailLog\Tests\TestCase;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

class BounceAlertsTest extends TestCase
{
    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        BounceAlerts::recipientsUsing(null);
        config()->set('filament-mail-log.alerts.enabled', true);
        config()->set('filament-mail-log.suppression.record', true);

        $this->admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->other = User::factory()->create(['email' => 'other@example.test']);
        Gate::define('receiveMailLogAlerts', fn (User $user): bool => $user->is($this->admin));
    }

    protected function tearDown(): void
    {
        BounceAlerts::recipientsUsing(null);

        parent::tearDown();
    }

    private function log(string $recipient = 'ann@example.test', ?string $type = null): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = MailStatus::Sent;
        $log->recipient = $recipient;
        $log->subject = 'Invoice 42';
        $log->type = $type;
        $log->save();

        return $log;
    }

    private function report(MailLog $log, DeliveryEventType $type = DeliveryEventType::Bounced, ?BounceType $bounce = BounceType::Hard, ?string $id = null): void
    {
        app(DeliveryTracker::class)->record(new DeliveryEvent('postmark', $id ?? Str::random(12), $type, now(), $log->recipient, $bounce, logIds: [$log->ulid], detail: 'mailbox unknown'));
    }

    private function alerts(User $user): int
    {
        return $user->notifications()->count();
    }

    public function test_it_is_off_by_default(): void
    {
        config()->set('filament-mail-log.alerts.enabled', false);

        $this->report($this->log());

        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_hard_bounce_notifies_only_the_users_the_gate_lets_in(): void
    {
        $this->report($this->log());

        $this->assertSame(1, $this->alerts($this->admin));
        $this->assertSame(0, $this->alerts($this->other));

        $data = $this->admin->notifications()->first()->data;
        $this->assertStringContainsString('ann@example.test', $data['title']);
        $this->assertStringContainsString('Invoice 42', $data['body']);
        $this->assertStringContainsString('mailbox unknown', $data['body']);
    }

    public function test_nobody_is_notified_while_the_gate_is_undefined(): void
    {
        $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);
        config()->set('filament-mail-log.alerts.gate', 'notDefinedAnywhere');

        $this->report($this->log());

        $this->assertFalse($gate->has('notDefinedAnywhere'));
        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_complaint_alerts_and_a_soft_bounce_or_delivery_does_not(): void
    {
        $this->report($this->log('a@example.test'), DeliveryEventType::Bounced, BounceType::Soft);
        $this->report($this->log('b@example.test'), DeliveryEventType::Delivered, null);
        $this->assertSame(0, $this->alerts($this->admin));

        $this->report($this->log('c@example.test'), DeliveryEventType::Complained, null);
        $this->assertSame(1, $this->alerts($this->admin));
        $this->assertStringContainsString('c@example.test', $this->admin->notifications()->first()->data['title']);
    }

    public function test_the_triggers_are_configurable(): void
    {
        config()->set('filament-mail-log.alerts.on', ['complaint']);

        $this->report($this->log());

        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_provider_redelivery_does_not_alert_twice(): void
    {
        $log = $this->log();

        $this->report($log, id: 'evt-1');
        $this->report($log, id: 'evt-1');

        $this->assertSame(1, $this->alerts($this->admin));
    }

    public function test_the_same_address_alerts_once_per_window(): void
    {
        $this->report($this->log());
        $this->report($this->log());

        $this->assertSame(1, $this->alerts($this->admin));
    }

    public function test_the_ceiling_holds_alerts_back_and_the_next_one_reports_them(): void
    {
        config()->set('filament-mail-log.alerts.throttle.max', 2);

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->report($this->log($name.'@example.test'));
        }

        $this->assertSame(2, $this->alerts($this->admin));

        Cache::forget('filament-mail-log:alerts:count');
        $this->report($this->log('e@example.test'));

        $this->assertSame(3, $this->alerts($this->admin));
        $body = $this->admin->notifications()->latest('created_at')->get()->map(fn ($n): string => $n->data['body'])->implode("\n");
        $this->assertStringContainsString('2 more alerts', $body);
    }

    public function test_the_mail_goes_to_the_configured_addresses_only(): void
    {
        config()->set('filament-mail-log.alerts.mail_to', 'ops@example.test, boss@example.test');
        config()->set('filament-mail-log.alerts.database', false);
        Mail::fake();

        $this->report($this->log());

        Mail::assertSent(BounceAlertMail::class, fn (BounceAlertMail $mail): bool => $mail->hasTo('ops@example.test') && $mail->hasTo('boss@example.test'));
        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_bounce_of_the_alert_mail_itself_never_alerts(): void
    {
        config()->set('filament-mail-log.alerts.mail_to', 'ops@example.test');

        $this->report($this->log('ops@example.test', BounceAlertMail::class));

        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_the_suppression_trigger_alerts_for_a_new_address_even_without_a_message(): void
    {
        config()->set('filament-mail-log.alerts.on', ['suppression']);

        $event = new DeliveryEvent('postmark', 'evt-x', DeliveryEventType::Bounced, now(), 'ghost@example.test', BounceType::Hard);
        app(DeliveryTracker::class)->record($event);
        app(DeliveryTracker::class)->record(new DeliveryEvent('postmark', 'evt-y', DeliveryEventType::Bounced, now(), 'ghost@example.test', BounceType::Hard));

        $this->assertSame(1, $this->alerts($this->admin));
        $this->assertStringContainsString('added to the suppression list', $this->admin->notifications()->first()->data['body']);
    }

    public function test_an_unmatched_event_does_not_alert_without_the_suppression_trigger(): void
    {
        app(DeliveryTracker::class)->record(new DeliveryEvent('postmark', 'evt-z', DeliveryEventType::Bounced, now(), 'ghost@example.test', BounceType::Hard));

        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_recipients_callback_replaces_the_gate(): void
    {
        BounceAlerts::recipientsUsing(fn (): array => [$this->other]);

        $this->report($this->log());

        $this->assertSame(1, $this->alerts($this->other));
        $this->assertSame(0, $this->alerts($this->admin));
    }

    public function test_a_failing_alert_never_breaks_the_webhook_processing(): void
    {
        config()->set('filament-mail-log.alerts.mail_to', 'ops@example.test');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $log = $this->log();
        $this->report($log);

        $this->assertSame(\Asignua\FilamentMailLog\Enums\DeliveryStatus::Bounced, $log->fresh()->delivery_status);
    }
}
