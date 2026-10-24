<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\DeliveryTracker;
use Asignua\FilamentMailLog\Tests\TestCase;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Workbench\App\Models\User;

class DeliveryUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewMailLog', fn (User $user): bool => true);
    }

    private function log(string $recipient): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = MailStatus::Sent;
        $log->recipient = $recipient;
        $log->subject = 'Hello';
        $log->save();

        return $log;
    }

    private function report(MailLog $log, DeliveryEventType $type, ?BounceType $bounce = null, ?string $detail = null): void
    {
        app(DeliveryTracker::class)->record(new DeliveryEvent('postmark', Str::random(12), $type, now(), $log->recipient, $bounce, logIds: [$log->ulid], detail: $detail));
    }

    public function test_the_list_shows_and_filters_by_delivery_status(): void
    {
        $delivered = $this->log('a@example.test');
        $bounced = $this->log('b@example.test');
        $silent = $this->log('c@example.test');
        $this->report($delivered, DeliveryEventType::Delivered);
        $this->report($bounced, DeliveryEventType::Bounced, BounceType::Hard);

        Livewire::test(ListMailLogs::class)
            ->assertTableColumnExists('delivery_status')
            ->assertTableColumnStateSet('delivery_status', \Asignua\FilamentMailLog\Enums\DeliveryStatus::Bounced, $bounced)
            ->assertCanSeeTableRecords([$delivered, $bounced, $silent])
            ->filterTable('delivery_status', ['bounced'])
            ->assertCanSeeTableRecords([$bounced])
            ->assertCanNotSeeTableRecords([$delivered, $silent]);
    }

    public function test_the_message_page_shows_the_event_timeline(): void
    {
        $log = $this->log('a@example.test');
        $this->report($log, DeliveryEventType::Delivered);
        $this->report($log, DeliveryEventType::Bounced, BounceType::Soft, 'mailbox full, try later');

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->assertSee(__('filament-mail-log::filament-mail-log.sections.delivery_events'))
            ->assertSee('mailbox full, try later')
            ->assertSee(__('filament-mail-log::filament-mail-log.event_types.delivered'))
            ->assertSee(__('filament-mail-log::filament-mail-log.bounce_types.soft'));
    }

    public function test_a_message_without_events_has_no_timeline(): void
    {
        $log = $this->log('a@example.test');

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->assertDontSee(__('filament-mail-log::filament-mail-log.sections.delivery_events'));
    }

    public function test_the_suppressed_status_has_a_label(): void
    {
        $this->assertSame(__('filament-mail-log::filament-mail-log.status.suppressed'), MailStatus::Suppressed->getLabel());
        $this->assertNotSame('filament-mail-log::filament-mail-log.status.suppressed', MailStatus::Suppressed->getLabel());
    }
}
