<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailTypes;
use Asignua\FilamentMailLog\Tests\Fixtures\ChildWelcomeMail;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Workbench\App\Models\User;

class ResourceTest extends TestCase
{
    public function test_nobody_gets_in_without_a_gate_or_a_callback(): void
    {
        $this->get('/admin/mail-logs')->assertForbidden();
        $this->assertFalse(MailLogResource::canViewAny());
    }

    public function test_the_default_gate_ability_opens_the_log(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);

        $this->get('/admin/mail-logs')->assertOk();
        $this->assertTrue(MailLogResource::canViewAny());
    }

    public function test_a_gate_that_says_no_keeps_the_log_closed(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => false);

        $this->get('/admin/mail-logs')->assertForbidden();
    }

    public function test_the_authorize_callback_wins_over_the_gate(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => false);
        MailLogPlugin::get()->authorize(fn (): bool => true);

        $this->get('/admin/mail-logs')->assertOk();

        MailLogPlugin::get()->authorize(false);
        Gate::define('viewMailLog', fn (User $user): bool => true);

        $this->get('/admin/mail-logs')->assertForbidden();
    }

    public function test_the_view_page_is_closed_too(): void
    {
        $log = $this->log();

        $this->get('/admin/mail-logs/'.$log->getKey())->assertForbidden();
    }

    public function test_the_log_is_read_only(): void
    {
        $this->allow();
        $log = $this->log();

        $this->assertFalse(MailLogResource::canCreate());
        $this->assertFalse(MailLogResource::canEdit($log));
        $this->assertFalse(MailLogResource::canDelete($log));
        $this->assertFalse(MailLogResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', MailLogResource::getPages());
        $this->assertArrayNotHasKey('edit', MailLogResource::getPages());
    }

    public function test_the_list_shows_records_and_filters_by_status(): void
    {
        $this->allow();
        $sent = $this->log(['status' => MailStatus::Sent]);
        $failed = $this->log(['status' => MailStatus::Failed]);

        Livewire::test(ListMailLogs::class)
            ->assertCanSeeTableRecords([$sent, $failed])
            ->filterTable('status', [MailStatus::Failed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$sent]);
    }

    public function test_it_filters_by_recipient_including_cc(): void
    {
        $this->allow();
        $ann = $this->log(['recipient' => 'ann@example.test', 'recipients' => ['to' => ['ann@example.test'], 'cc' => [], 'bcc' => []]]);
        $bob = $this->log(['recipient' => 'bob@example.test', 'recipients' => ['to' => ['bob@example.test'], 'cc' => ['boss@corp.test'], 'bcc' => []]]);

        Livewire::test(ListMailLogs::class)
            ->filterTable('recipient', ['recipient' => 'ann@'])
            ->assertCanSeeTableRecords([$ann])
            ->assertCanNotSeeTableRecords([$bob])
            ->removeTableFilter('recipient')
            ->filterTable('recipient', ['recipient' => 'boss@corp'])
            ->assertCanSeeTableRecords([$bob])
            ->assertCanNotSeeTableRecords([$ann]);
    }

    public function test_it_filters_by_period_with_inclusive_days(): void
    {
        $this->allow();
        $old = $this->log(['created_at' => '2026-03-01 23:59:00']);
        $inside = $this->log(['created_at' => '2026-03-10 12:00:00']);
        $lastDay = $this->log(['created_at' => '2026-03-15 23:30:00']);
        $after = $this->log(['created_at' => '2026-03-16 00:30:00']);

        Livewire::test(ListMailLogs::class)
            ->filterTable('period', ['from' => '2026-03-10', 'until' => '2026-03-15'])
            ->assertCanSeeTableRecords([$inside, $lastDay])
            ->assertCanNotSeeTableRecords([$old, $after]);
    }

    public function test_it_filters_by_text_in_the_body_and_by_type(): void
    {
        $this->allow();
        $a = $this->log(['html_body' => '<p>needle here</p>', 'type' => WelcomeMail::class]);
        $b = $this->log(['html_body' => '<p>other</p>', 'type' => 'App\\Mail\\Other']);

        Livewire::test(ListMailLogs::class)
            ->filterTable('body', ['body' => 'needle'])
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b])
            ->removeTableFilter('body')
            ->filterTable('type', 'App\\Mail\\Other')
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);
    }

    public function test_it_searches_by_subject_and_recipient(): void
    {
        $this->allow();
        $a = $this->log(['subject' => 'Your invoice', 'recipient' => 'zed@example.test']);
        $b = $this->log(['subject' => 'Welcome', 'recipient' => 'amy@example.test']);

        Livewire::test(ListMailLogs::class)
            ->searchTable('invoice')
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b])
            ->searchTable('amy@')
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$a]);
    }

    public function test_the_view_page_renders_the_details_and_the_error(): void
    {
        $this->allow();
        $log = $this->log([
            'status' => MailStatus::Failed,
            'subject' => 'Broken',
            'error' => 'Connection refused',
            'attachments' => [['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 2048]],
            'headers' => ['X-Custom' => ['one', 'two']],
        ]);

        Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Broken')
            ->assertSee('Connection refused')
            ->assertSee('a.pdf')
            ->assertSee('X-Custom');
    }

    public function test_the_html_preview_is_a_sandboxed_iframe_and_the_body_is_escaped(): void
    {
        $this->allow();
        $log = $this->log(['html_body' => '"><script>alert(1)</script><img src=x onerror=alert(2)>']);

        $html = Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])->html();

        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringNotContainsString('allow-scripts', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('srcdoc="', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_frame_component_escapes_a_quote_breakout_and_adds_a_csp(): void
    {
        $out = Blade::render('<x-filament-mail-log::mail-frame :html="$html" />', ['html' => '" onload="alert(1)" x="<b>y</b>']);

        $this->assertStringContainsString('sandbox=""', $out);
        $this->assertStringContainsString('Content-Security-Policy', $out);
        $this->assertStringNotContainsString('onload="alert(1)"', $out);
        $this->assertStringNotContainsString('<b>y</b>', $out);
    }

    public function test_the_csp_can_be_turned_off(): void
    {
        config()->set('filament-mail-log.preview.csp', null);

        $out = Blade::render('<x-filament-mail-log::mail-frame html="<p>x</p>" />');

        $this->assertStringNotContainsString('Content-Security-Policy', $out);
    }

    public function test_the_text_tab_escapes_the_body(): void
    {
        $this->allow();
        $log = $this->log(['text_body' => '<script>alert(3)</script>', 'html_body' => null]);

        $html = Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])->html();

        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
    }

    public function test_a_record_without_a_body_says_so(): void
    {
        $this->allow();
        $log = $this->log(['html_body' => null, 'text_body' => null]);

        Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])
            ->assertSee(__('filament-mail-log::filament-mail-log.notices.no_body'));
    }

    public function test_navigation_is_configurable(): void
    {
        $this->allow();
        MailLogPlugin::get()->navigationGroup('System')->navigationSort(7)->navigationIcon('heroicon-o-bell');

        $this->assertSame('System', MailLogResource::getNavigationGroup());
        $this->assertSame(7, MailLogResource::getNavigationSort());
        $this->assertSame('heroicon-o-bell', MailLogResource::getNavigationIcon());
    }

    public function test_the_resource_can_be_left_out_of_the_panel(): void
    {
        $plugin = MailLogPlugin::make()->resource(false);
        $panel = \Filament\Panel::make()->id('other')->path('other');
        $plugin->register($panel);

        $this->assertNotContains(MailLogResource::class, $panel->getResources());
    }

    public function test_type_labels_come_from_the_registry_then_the_parent_then_the_class_name(): void
    {
        config()->set('filament-mail-log.types', [WelcomeMail::class => 'Welcome mail']);

        $this->assertSame('Welcome mail', MailTypes::label(WelcomeMail::class));
        $this->assertSame('Welcome mail', MailTypes::label(ChildWelcomeMail::class));
        $this->assertSame('Gone', MailTypes::label('App\\Mail\\Gone'));
        $this->assertSame('—', MailTypes::label(null));
    }

    private function allow(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function log(array $attributes = []): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = MailStatus::Sent;
        $log->recipient = 'a@example.test';
        $log->subject = 'Subject';
        $log->html_body = '<p>Body</p>';
        $log->forceFill($attributes);
        $log->save();

        return $log;
    }
}
