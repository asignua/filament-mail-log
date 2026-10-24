<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogEvent;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\DeliveryTracker;
use Asignua\FilamentMailLog\Support\TrackingSchema;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DeliveryTrackingTest extends TestCase
{
    private int $sequence = 0;

    private function log(): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = MailStatus::Sent;
        $log->recipient = 'ann@example.test';
        $log->save();

        return $log;
    }

    private function event(MailLog $log, DeliveryEventType $type, ?BounceType $bounce = null, ?CarbonInterface $at = null): DeliveryEvent
    {
        return new DeliveryEvent(
            provider: 'test',
            id: 'e'.++$this->sequence,
            type: $type,
            occurredAt: $at ?? Carbon::now()->addSeconds($this->sequence),
            recipient: 'ann@example.test',
            bounce: $bounce,
            logIds: [$log->ulid],
        );
    }

    /**
     * @param list<array{0: DeliveryEventType, 1?: BounceType|null}> $sequence
     */
    private function play(MailLog $log, array $sequence): DeliveryStatus
    {
        foreach ($sequence as $step) {
            app(DeliveryTracker::class)->record($this->event($log, $step[0], $step[1] ?? null));
        }

        return $log->fresh()->delivery_status;
    }

    public function test_the_status_starts_empty(): void
    {
        $this->assertNull($this->log()->fresh()->delivery_status);
    }

    public function test_a_status_never_goes_down(): void
    {
        $this->assertSame(DeliveryStatus::Bounced, $this->play($this->log(), [
            [DeliveryEventType::Bounced, BounceType::Hard],
            [DeliveryEventType::Delivered],
            [DeliveryEventType::Opened],
            [DeliveryEventType::Deferred],
        ]), 'a late "delivered" / "opened" must not hide a hard bounce');
    }

    public function test_it_moves_up_as_things_happen(): void
    {
        $log = $this->log();

        $this->assertSame(DeliveryStatus::Deferred, $this->play($log, [[DeliveryEventType::Deferred]]));
        $this->assertSame(DeliveryStatus::SoftBounced, $this->play($log, [[DeliveryEventType::Bounced, BounceType::Soft]]));
        $this->assertSame(DeliveryStatus::Delivered, $this->play($log, [[DeliveryEventType::Delivered]]), 'a successful retry after a soft bounce');
        $this->assertSame(DeliveryStatus::Opened, $this->play($log, [[DeliveryEventType::Opened]]));
        $this->assertSame(DeliveryStatus::Clicked, $this->play($log, [[DeliveryEventType::Clicked], [DeliveryEventType::Opened]]));
        $this->assertSame(DeliveryStatus::Bounced, $this->play($log, [[DeliveryEventType::Bounced, BounceType::Hard]]), 'an asynchronous bounce after delivery wins');
        $this->assertSame(DeliveryStatus::Complained, $this->play($log, [[DeliveryEventType::Complained]]));
        $this->assertSame(DeliveryStatus::Complained, $this->play($log, [[DeliveryEventType::Bounced, BounceType::Hard], [DeliveryEventType::Clicked]]));
    }

    public function test_the_order_of_arrival_does_not_change_the_result(): void
    {
        $steps = [
            [DeliveryEventType::Delivered],
            [DeliveryEventType::Bounced, BounceType::Soft],
            [DeliveryEventType::Opened],
            [DeliveryEventType::Deferred],
        ];
        $results = [];

        foreach ([$steps, array_reverse($steps), [$steps[2], $steps[0], $steps[3], $steps[1]]] as $order) {
            $results[] = $this->play($this->log(), $order);
        }

        $this->assertSame([DeliveryStatus::Opened, DeliveryStatus::Opened, DeliveryStatus::Opened], $results);
    }

    public function test_the_status_time_is_the_time_of_the_event_that_set_it(): void
    {
        $log = $this->log();
        $at = Carbon::parse('2026-10-20 10:00:00', 'UTC');

        app(DeliveryTracker::class)->record($this->event($log, DeliveryEventType::Delivered, null, $at));
        app(DeliveryTracker::class)->record($this->event($log, DeliveryEventType::Deferred, null, $at->copy()->addHour()));

        $this->assertSame(DeliveryStatus::Delivered, $log->fresh()->delivery_status);
        $this->assertTrue($log->fresh()->delivery_status_at->equalTo($at));
    }

    public function test_the_log_row_status_is_not_touched(): void
    {
        $log = $this->log();
        $this->play($log, [[DeliveryEventType::Bounced, BounceType::Hard]]);

        $this->assertSame(MailStatus::Sent, $log->fresh()->status);
    }

    public function test_events_come_back_oldest_first_through_the_relation(): void
    {
        $log = $this->log();
        $now = Carbon::now();
        $tracker = app(DeliveryTracker::class);

        $tracker->record($this->event($log, DeliveryEventType::Opened, null, $now->copy()->addMinute()));
        $tracker->record($this->event($log, DeliveryEventType::Delivered, null, $now));

        $this->assertSame([DeliveryEventType::Delivered, DeliveryEventType::Opened], $log->events->pluck('type')->all());
    }

    public function test_pruning_removes_the_events_with_their_message(): void
    {
        $old = $this->log();
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        $new = $this->log();
        $this->play($old, [[DeliveryEventType::Delivered]]);
        $this->play($new, [[DeliveryEventType::Delivered]]);

        app(MailLogRepository::class)->prune(now()->subDays(90));

        $this->assertSame([$new->getKey()], MailLogEvent::query()->pluck('mail_log_id')->all());
    }

    public function test_outgoing_mail_is_stamped_only_when_a_provider_is_on(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail);
        $plain = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertFalse($plain->getHeaders()->has('X-Metadata-mail_log_id'));
        $this->assertTrue($plain->getHeaders()->has('X-Mail-Log-Id'));

        config()->set('filament-mail-log.webhooks.providers.postmark.enabled', true);
        config()->set('filament-mail-log.webhooks.stamp_headers', ['X-PM-Metadata-mail_log_id']);
        Mail::to('ann@example.test')->send(new WelcomeMail);

        $log = MailLog::query()->latest('id')->first();
        $stamped = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame($log->ulid, $stamped->getHeaders()->get('X-Metadata-mail_log_id')->getBodyAsString());
        $this->assertSame($log->ulid, $stamped->getHeaders()->get('X-PM-Metadata-mail_log_id')->getBodyAsString());
        $this->assertSame($log->ulid, $stamped->getHeaders()->get('X-Mail-Log-Id')->getBodyAsString());
        // The stamp is not part of the stored "Headers" tab.
        $this->assertArrayNotHasKey('X-Metadata-mail_log_id', $log->headers ?? []);
    }

    public function test_the_stamp_can_be_switched_off(): void
    {
        config()->set('filament-mail-log.webhooks.providers.postmark.enabled', true);
        config()->set('filament-mail-log.webhooks.stamp_metadata', false);

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertFalse(Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHeaders()->has('X-Metadata-mail_log_id'));
    }

    public function test_a_stamped_message_is_still_correlated_by_its_own_header_end_to_end(): void
    {
        config()->set('filament-mail-log.webhooks.providers.mailgun.enabled', true);
        Mail::to('ann@example.test')->send(new WelcomeMail);

        $log = MailLog::query()->sole();
        $original = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $echoed = $original->getHeaders()->get('X-Metadata-mail_log_id')->getBodyAsString();

        app(DeliveryTracker::class)->record(new DeliveryEvent('mailgun', 'evt', DeliveryEventType::Delivered, now(), 'ann@example.test', logIds: [strtolower($echoed)]));

        $this->assertSame(DeliveryStatus::Delivered, $log->fresh()->delivery_status);
    }

    public function test_the_log_keeps_working_before_the_new_migrations_ran(): void
    {
        Schema::drop((string) config('filament-mail-log.webhooks.events_table'));
        Schema::drop((string) config('filament-mail-log.suppression.table'));
        TrackingSchema::forget();
        config()->set('filament-mail-log.suppression.enabled', true);

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
        $this->assertFalse(TrackingSchema::hasEventsTable());
        $this->assertSame(1, app(MailLogRepository::class)->prune(now()->addDay()));
    }
}
