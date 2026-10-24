<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\Mail\ResentMail;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailResender;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Workbench\App\Models\User;

class ResendTest extends TestCase
{
    public function test_it_is_closed_without_its_own_ability_even_for_someone_who_can_view(): void
    {
        $this->allowView();
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionHidden('resend');
        Livewire::test(ListMailLogs::class)->assertTableActionHidden('resend', $log);
        $this->assertFalse(MailLogPlugin::get()->canResend());
    }

    public function test_the_ability_opens_it_and_a_callback_wins(): void
    {
        $this->allowView();
        $log = $this->log();
        Gate::define(MailLogPlugin::RESEND_ABILITY, fn (User $user): bool => true);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionVisible('resend');
        Livewire::test(ListMailLogs::class)->assertTableActionVisible('resend', $log);

        MailLogPlugin::get()->authorizeResend(false);
        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionHidden('resend');
    }

    public function test_seeing_the_resend_permission_is_not_enough_without_view_access(): void
    {
        Gate::define(MailLogPlugin::RESEND_ABILITY, fn (User $user): bool => true);

        $this->assertFalse(MailLogPlugin::get()->canResend());
    }

    public function test_a_crafted_call_is_refused_when_the_permission_is_missing(): void
    {
        $this->allowView();
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->call('mountAction', 'resend')
            ->assertActionNotMounted();

        $this->assertSame(1, MailLog::query()->count());
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
    }

    public function test_it_is_hidden_when_nothing_was_stored_to_send(): void
    {
        $this->allowResend();
        $log = $this->log(['html_body' => null, 'text_body' => null]);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionHidden('resend');
    }

    public function test_the_modal_is_prefilled_with_the_original_recipients(): void
    {
        $this->allowResend();
        $log = $this->log(['recipients' => ['to' => ['a@example.test', 'Bob <b@example.test>'], 'cc' => ['c@example.test'], 'bcc' => []]]);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->mountAction('resend')
            ->assertSchemaStateSet(['to' => ['a@example.test', 'Bob <b@example.test>'], 'with_cc' => false, 'with_bcc' => false]);
    }

    public function test_addresses_are_validated(): void
    {
        $this->allowResend();
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->callAction('resend', ['to' => ['not-an-email']])
            ->assertHasActionErrors(['to.0']);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->callAction('resend', ['to' => []])
            ->assertHasActionErrors(['to' => 'required']);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->callAction('resend', ['to' => ['a@example.test'], 'with_cc' => true, 'cc' => ['nope']])
            ->assertHasActionErrors(['cc.0']);

        $this->assertCount(0, Mail::getSymfonyTransport()->messages());
    }

    public function test_it_sends_a_new_mail_from_the_stored_copy_and_links_the_new_row(): void
    {
        $this->allowResend();
        $log = $this->log([
            'subject' => 'Hello',
            'sender' => 'Acme <hello@acme.test>',
            'text_body' => 'plain body',
            'html_body' => '<p>html body</p>',
            'headers' => ['Reply-To' => ['Support <help@acme.test>'], 'X-Priority' => ['1'], 'Authorization' => ['Bearer x'], 'Subject' => ['Hello']],
        ]);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])
            ->callAction('resend', ['to' => ['other@example.test'], 'with_cc' => true, 'cc' => ['cc@example.test'], 'with_bcc' => false, 'bcc' => ['ignored@example.test']])
            ->assertHasNoActionErrors();

        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('Hello', $email->getSubject());
        $this->assertSame('other@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('cc@example.test', $email->getCc()[0]->getAddress());
        $this->assertSame([], $email->getBcc());
        $this->assertSame('hello@acme.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('help@acme.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('plain body', $email->getTextBody());
        $this->assertSame('<p>html body</p>', $email->getHtmlBody());
        $this->assertSame('1', $email->getHeaders()->get('X-Priority')?->getBodyAsString());
        $this->assertNull($email->getHeaders()->get('Authorization'));

        $new = MailLog::query()->where('id', '!=', $log->getKey())->sole();
        $this->assertSame($log->getKey(), $new->resent_from_id);
        $this->assertSame(MailStatus::Sent, $new->status);
        $this->assertSame(ResentMail::class, $new->type);
        $this->assertSame(['other@example.test'], $new->recipients['to']);
        $this->assertNull($log->fresh()->resent_from_id);
    }

    public function test_a_text_only_message_is_resent(): void
    {
        $this->allowResend();
        $log = $this->log(['html_body' => null, 'text_body' => 'only text']);

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->callAction('resend', ['to' => ['x@example.test']]);

        $email = Mail::getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('only text', $email->getTextBody());
        $this->assertNull($email->getHtmlBody());
    }

    public function test_the_queue_option_pushes_a_job_and_the_row_is_still_linked(): void
    {
        $this->allowResend();
        config()->set('filament-mail-log.resend.queue', true);
        $log = $this->log();

        Queue::fake();
        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->callAction('resend', ['to' => ['x@example.test']]);
        Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job): bool => $job->mailable instanceof ResentMail);
    }

    public function test_a_queued_resend_is_linked_through_the_real_queue(): void
    {
        config()->set('filament-mail-log.resend.queue', true);
        $log = $this->log();

        app(MailResender::class)->resend($log, ['x@example.test']);

        $new = MailLog::query()->where('id', '!=', $log->getKey())->sole();
        $this->assertSame($log->getKey(), $new->resent_from_id);
    }

    public function test_the_configured_mailer_is_used(): void
    {
        $this->allowResend();
        config()->set('mail.mailers.other', ['transport' => 'array']);
        config()->set('filament-mail-log.resend.mailer', 'other');
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->callAction('resend', ['to' => ['x@example.test']]);

        $this->assertCount(1, Mail::mailer('other')->getSymfonyTransport()->messages());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_a_redacted_copy_shows_the_warning_and_a_plain_one_does_not(): void
    {
        $this->allowResend();
        $redacted = $this->log(['redacted' => true]);
        $plain = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $redacted->getKey()])
            ->mountAction('resend')
            ->assertMountedActionModalSee(__('filament-mail-log::filament-mail-log.resend.redacted_title'));

        Livewire::test(ViewMailLog::class, ['record' => $plain->getKey()])
            ->mountAction('resend')
            ->assertMountedActionModalDontSee(__('filament-mail-log::filament-mail-log.resend.redacted_title'));
    }

    public function test_the_attachments_note_is_shown_only_when_attachments_were_logged(): void
    {
        $this->allowResend();
        $with = $this->log(['attachments' => [['name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10]]]);
        $without = $this->log();
        $title = __('filament-mail-log::filament-mail-log.resend.attachments_title');

        Livewire::test(ViewMailLog::class, ['record' => $with->getKey()])->mountAction('resend')->assertMountedActionModalSee($title);
        Livewire::test(ViewMailLog::class, ['record' => $without->getKey()])->mountAction('resend')->assertMountedActionModalDontSee($title);
    }

    public function test_the_view_page_shows_where_a_message_was_resent_from_and_how_often(): void
    {
        $this->allowView();
        $original = $this->log();
        $copy = $this->log(['resent_from_id' => $original->getKey()]);
        $this->log(['resent_from_id' => $original->getKey()]);

        Livewire::test(ViewMailLog::class, ['record' => $copy->getKey()])
            ->assertSee('#'.$original->getKey());

        Livewire::test(ViewMailLog::class, ['record' => $original->getKey()])
            ->assertSee(trans_choice(__('filament-mail-log::filament-mail-log.resend.times'), 2));
    }

    public function test_the_row_action_resends_too(): void
    {
        $this->allowResend();
        $log = $this->log();

        Livewire::test(ListMailLogs::class)
            ->callTableAction('resend', $log, ['to' => ['row@example.test']])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, MailLog::query()->where('resent_from_id', $log->getKey())->count());
    }

    public function test_a_host_that_has_not_run_the_new_migration_keeps_logging_and_hides_the_action(): void
    {
        $this->allowResend();
        $log = $this->log();

        Schema::table('mail_logs', function (Blueprint $table): void {
            $table->dropIndex('ml_resent_from_idx');
            $table->dropColumn('resent_from_id');
        });
        MailLog::forgetColumnCache();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionHidden('resend');

        Mail::raw('hello', fn ($message) => $message->to('a@example.test'));
        $this->assertSame(2, MailLog::query()->count());

        MailLog::forgetColumnCache();
    }

    public function test_the_feature_can_be_switched_off(): void
    {
        $this->allowResend();
        config()->set('filament-mail-log.resend.enabled', false);
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getKey()])->assertActionHidden('resend');
    }

    private function allowView(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
    }

    private function allowResend(): void
    {
        $this->allowView();
        Gate::define(MailLogPlugin::RESEND_ABILITY, fn (User $user): bool => true);
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
        $log->recipients = ['to' => ['a@example.test'], 'cc' => [], 'bcc' => []];
        $log->subject = 'Subject';
        $log->html_body = '<p>Body</p>';
        $log->forceFill($attributes);
        $log->save();

        return $log;
    }
}
