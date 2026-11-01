<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Filament\RelationManagers\MailLogsRelationManager;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogRelation;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Orders\Pages\ViewOrder;
use Workbench\App\Mail\OrderShipped;
use Workbench\App\Models\Order;
use Workbench\App\Models\User;
use Workbench\App\Notifications\OrderPaid;

class RelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewMailLog', fn (User $user): bool => true);
    }

    private function order(string $number = 'SO-1'): Order
    {
        return Order::query()->create(['number' => $number, 'customer_name' => 'Ann', 'customer_email' => 'ann@example.test', 'total_cents' => 1000]);
    }

    public function test_a_mailable_that_declares_its_models_is_linked_to_them(): void
    {
        $order = $this->order();
        $user = User::factory()->create();

        Mail::to('ann@example.test')->send((new OrderShipped($order))->relatedTo($user));

        $log = MailLog::query()->sole();
        $this->assertEqualsCanonicalizing(
            [$order->getMorphClass().'#'.$order->id, $user->getMorphClass().'#'.$user->id],
            $log->relatedLinks->map(fn (MailLogRelation $l): string => $l->related_type.'#'.$l->related_id)->all(),
        );
        $this->assertSame([$log->id], $order->mailLogs()->pluck('mail_logs.id')->all());
        $this->assertSame([$log->id], $user->mailLogs()->pluck('mail_logs.id')->all());
    }

    public function test_the_models_never_reach_the_message_that_is_sent(): void
    {
        Mail::to('ann@example.test')->send(new OrderShipped($this->order()));

        $headers = MailLog::query()->sole()->headers;
        $this->assertStringNotContainsString('relations', strtolower(json_encode($headers, JSON_THROW_ON_ERROR)));
    }

    public function test_a_plain_mailable_has_no_links(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertSame(0, MailLogRelation::query()->count());
    }

    public function test_a_mail_notification_is_linked_to_its_notifiable_and_its_declared_models(): void
    {
        $order = $this->order();
        $user = User::factory()->create(['email' => 'ann@example.test']);

        $user->notify((new OrderPaid)->relatedTo($order));

        $links = MailLog::query()->sole()->relatedLinks->map(fn (MailLogRelation $l): string => $l->related_type.'#'.$l->related_id)->all();
        $this->assertEqualsCanonicalizing([$user->getMorphClass().'#'.$user->id, $order->getMorphClass().'#'.$order->id], $links);
    }

    public function test_an_on_demand_notifiable_is_not_linked(): void
    {
        Notification::route('mail', 'anon@example.test')->notify(new OrderPaid);

        $this->assertSame(0, MailLogRelation::query()->count());
    }

    public function test_notifiable_detection_can_be_switched_off(): void
    {
        config()->set('filament-mail-log.relations.notifiable', false);
        User::factory()->create(['email' => 'ann@example.test'])->notify(new OrderPaid);

        $this->assertSame(0, MailLogRelation::query()->count());
    }

    public function test_the_whole_feature_can_be_switched_off(): void
    {
        config()->set('filament-mail-log.relations.enabled', false);
        Mail::to('ann@example.test')->send(new OrderShipped($this->order()));

        $this->assertSame(1, MailLog::query()->count());
        $this->assertSame(0, MailLogRelation::query()->count());
    }

    public function test_the_number_of_links_is_capped_and_unsaved_models_are_ignored(): void
    {
        config()->set('filament-mail-log.relations.max', 2);
        $mail = (new OrderShipped($this->order()))->relatedTo($this->order('SO-2'), $this->order('SO-3'), new Order);

        Mail::to('ann@example.test')->send($mail);

        $this->assertSame(2, MailLogRelation::query()->count());
    }

    public function test_a_queued_mailable_and_notification_are_linked_when_queued(): void
    {
        $order = $this->order();
        $user = User::factory()->create(['email' => 'ann@example.test']);

        $mailable = (new OrderShipped($order))->to('ann@example.test');
        event(new JobQueued('fake', 'default', '42', new SendQueuedMailable($mailable), '{}', null));

        $notification = (new OrderPaid)->relatedTo($order);
        event(new JobQueued('fake', 'default', '43', new SendQueuedNotifications(collect([$user]), $notification, ['mail']), '{}', null));

        $mailQueued = MailLog::query()->where('job_id', '42')->sole();
        $notificationQueued = MailLog::query()->where('job_id', '43')->sole();

        $this->assertSame([Order::class], $mailQueued->relatedLinks->pluck('related_type')->all());
        $this->assertSame(2, $notificationQueued->relatedLinks()->count());
    }

    public function test_a_resend_inherits_the_links_of_the_original(): void
    {
        $order = $this->order();
        Mail::to('ann@example.test')->send(new OrderShipped($order));
        $original = MailLog::query()->sole();

        app(\Asignua\FilamentMailLog\Support\MailResender::class)->resend($original, ['ann@example.test']);

        $resent = MailLog::query()->whereKeyNot($original->id)->sole();
        $this->assertSame($original->id, $resent->resent_from_id);
        $this->assertSame(1, $resent->relatedLinks()->count());
        $this->assertSame(2, $order->mailLogs()->count());
    }

    public function test_pruning_removes_the_links_with_their_message(): void
    {
        Mail::to('ann@example.test')->send(new OrderShipped($this->order()));
        MailLog::query()->update(['created_at' => now()->subDays(200)]);

        app(MailLogRepository::class)->prune(now()->subDays(90));

        $this->assertSame(0, MailLog::query()->count());
        $this->assertSame(0, MailLogRelation::query()->count());
    }

    public function test_logging_survives_a_host_that_has_not_run_the_migration(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('mail_log_relations');
        \Asignua\FilamentMailLog\Support\TrackingSchema::forget();

        Mail::to('ann@example.test')->send(new OrderShipped($this->order()));

        $this->assertSame(1, MailLog::query()->count());
    }

    public function test_the_relation_manager_lists_only_the_messages_of_the_record(): void
    {
        $mine = $this->order('SO-1');
        $other = $this->order('SO-2');
        Mail::to('ann@example.test')->send(new OrderShipped($mine));
        Mail::to('bob@example.test')->send(new OrderShipped($other));
        $mineLog = $mine->mailLogs()->firstOrFail();
        $otherLog = $other->mailLogs()->firstOrFail();

        Livewire::test(MailLogsRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$mineLog])
            ->assertCanNotSeeTableRecords([$otherLog]);
    }

    public function test_the_relation_manager_is_closed_like_the_log(): void
    {
        $order = $this->order();
        $this->assertTrue(MailLogsRelationManager::canViewForRecord($order, ViewOrder::class));

        Gate::define('viewMailLog', fn (User $user): bool => false);
        $this->assertFalse(MailLogsRelationManager::canViewForRecord($order, ViewOrder::class));
    }

    public function test_the_order_page_shows_the_emails_tab(): void
    {
        $order = $this->order();
        Mail::to('ann@example.test')->send(new OrderShipped($order));

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSeeHtml('MailLogsRelationManager');
    }

    public function test_the_list_filters_by_related_model(): void
    {
        $order = $this->order();
        $other = $this->order('SO-2');
        Mail::to('ann@example.test')->send(new OrderShipped($order));
        Mail::to('bob@example.test')->send(new OrderShipped($other));
        Mail::to('cat@example.test')->send(new WelcomeMail);
        $first = $order->mailLogs()->firstOrFail();
        $second = $other->mailLogs()->firstOrFail();
        $plain = MailLog::query()->where('recipient', 'cat@example.test')->firstOrFail();

        Livewire::test(ListMailLogs::class)
            ->filterTable('related', ['related_type' => $order->getMorphClass(), 'related_id' => (string) $order->id])
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second, $plain])
            ->resetTableFilters()
            ->filterTable('related', ['related_type' => $order->getMorphClass(), 'related_id' => ''])
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_the_message_page_names_the_related_records(): void
    {
        $order = $this->order('SO-77');
        Mail::to('ann@example.test')->send(new OrderShipped($order));

        Livewire::test(ViewMailLog::class, ['record' => $order->mailLogs()->firstOrFail()->getKey()])
            ->assertSee(__('filament-mail-log::filament-mail-log.sections.related'))
            ->assertSee('Order: SO-77');
    }

    public function test_eager_loading_counting_and_filtering_work_on_the_typed_key(): void
    {
        $with = $this->order('SO-1');
        $without = $this->order('SO-2');
        Mail::to('ann@example.test')->send(new OrderShipped($with));
        Mail::to('ann@example.test')->send(new OrderShipped($with));

        $loaded = Order::query()->with('mailLogs')->orderBy('id')->get();
        $this->assertCount(2, $loaded->first()->mailLogs);
        $this->assertCount(0, $loaded->last()->mailLogs);

        $counted = Order::query()->withCount('mailLogs')->orderBy('id')->get();
        $this->assertSame([2, 0], $counted->pluck('mail_logs_count')->all());

        $this->assertSame([$with->id], Order::query()->whereHas('mailLogs')->pluck('id')->all());
        $this->assertSame([$without->id], Order::query()->whereDoesntHave('mailLogs')->pluck('id')->all());
    }

    public function test_the_key_column_is_an_integer_by_default(): void
    {
        $this->assertSame('integer', \Illuminate\Support\Facades\Schema::getColumnType('mail_log_relations', 'related_id'));
    }

    public function test_a_string_key_type_stores_string_keys(): void
    {
        \Illuminate\Support\Facades\Schema::drop('mail_log_relations');
        config()->set('filament-mail-log.relations.key_type', 'string');
        (include __DIR__.'/../../database/migrations/create_mail_log_relations_table.php.stub')->up();
        \Asignua\FilamentMailLog\Support\TrackingSchema::forget();

        Mail::to('ann@example.test')->send(new OrderShipped($order = $this->order()));

        $this->assertSame((string) $order->id, MailLogRelation::query()->sole()->related_id);
        $this->assertSame(1, $order->mailLogs()->count());
    }

    public function test_a_key_the_column_cannot_hold_is_skipped(): void
    {
        config()->set('filament-mail-log.relations.key_type', 'int');
        $log = MailLog::query()->create(['ulid' => 'X', 'status' => 'sent', 'recipient' => 'a@example.test']);

        app(\Asignua\FilamentMailLog\Repositories\MailLogRelationRepository::class)->attach($log, [['App\\Thing', '019f2c3e-uuid'], ['App\\Thing', 7]]);

        $this->assertSame([7], MailLogRelation::query()->pluck('related_id')->map(fn ($v): int => (int) $v)->all());
    }

    public function test_the_filter_with_an_id_the_column_cannot_hold_matches_nothing(): void
    {
        $order = $this->order();
        Mail::to('ann@example.test')->send(new OrderShipped($order));

        Livewire::test(ListMailLogs::class)
            ->filterTable('related', ['related_type' => $order->getMorphClass(), 'related_id' => 'abc'])
            ->assertCountTableRecords(0);
    }

    public function test_related_to_keeps_only_pairs_so_no_attributes_are_queued(): void
    {
        $order = $this->order();
        $other = $this->order('SO-9');
        $mail = (new OrderShipped($order))->relatedTo($other);

        $this->assertSame([[Order::class, $other->id]], $mail->mailLogRelatedRefs());
        $this->assertStringNotContainsString('customer_email', serialize($mail->mailLogRelatedRefs()));
    }

    public function test_a_deleted_related_model_is_still_linked_by_its_pair_but_never_resurrected(): void
    {
        $order = $this->order();
        $mail = (new OrderShipped($this->order('SO-5')))->relatedTo($order);
        $order->delete();

        $restored = unserialize(serialize($mail));
        Mail::to('ann@example.test')->send($restored);

        $this->assertSame(0, Order::query()->whereKey($order->id)->count());
        $this->assertSame(2, MailLogRelation::query()->count());
    }

    public function test_the_view_action_links_to_the_message_page_instead_of_an_empty_modal(): void
    {
        $order = $this->order();
        Mail::to('ann@example.test')->send(new OrderShipped($order));
        $log = $order->mailLogs()->firstOrFail();

        Livewire::test(MailLogsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertTableActionHasUrl('view', \Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource::getUrl('view', ['record' => $log]), $log);
    }

    public function test_the_trait_wiring_survives_a_mailable_that_aliases_it(): void
    {
        $mail = new class(new Order) extends \Illuminate\Mail\Mailable implements \Asignua\FilamentMailLog\Contracts\RelatesToMailLog
        {
            use \Asignua\FilamentMailLog\Concerns\HasMailLogRelations {
                additionalMessageData as protected mailLogAdditionalMessageData;
            }

            public function __construct(public Order $order) {}

            protected function additionalMessageData(): array
            {
                return array_merge($this->mailLogAdditionalMessageData(), ['extra' => 1]);
            }
        };

        $data = (new \ReflectionMethod($mail, 'additionalMessageData'))->invoke($mail);

        $this->assertArrayHasKey('extra', $data);
        $this->assertArrayHasKey(\Asignua\FilamentMailLog\Support\MailRelations::DATA_KEY, $data);
    }
}
