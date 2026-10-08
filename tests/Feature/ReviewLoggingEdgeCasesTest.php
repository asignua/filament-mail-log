<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\Listeners\LogOutgoingMail;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\LogConnection;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Tests\Fixtures\FailingTransport;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Workbench\App\Models\User;

/**
 * Independent review (08.10.2026): lifecycle / panel edge cases beyond the author's suite.
 *
 * Tests named `test_bug_*` document defects found in the review; they FAIL until the plugin is fixed.
 */
class ReviewLoggingEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MailLogContext::reset();
        config()->set('mail.mailers.failing', ['transport' => 'failing']);
        Mail::extend('failing', fn (): FailingTransport => new FailingTransport);
    }

    public function test_a_queued_mail_notification_gets_a_row_that_the_worker_continues(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('mail', ['ann@example.test' => 'Ann']);

        event(new JobQueued('fake', 'default', '501', new SendQueuedNotifications($notifiable, new ReviewMailNotification, ['mail']), '{}', null));

        $queued = MailLog::query()->sole();
        $this->assertSame(MailStatus::Queued, $queued->status);
        $this->assertSame(ReviewMailNotification::class, $queued->type);
        $this->assertSame(['Ann <ann@example.test>'], $queued->recipients['to'] ?? null);

        event(new JobProcessing('fake', $this->job('501')));
        $notifiable->notifyNow(new ReviewMailNotification);
        event(new JobProcessed('fake', $this->job('501')));

        $log = MailLog::query()->sole();
        $this->assertSame($queued->id, $log->id);
        $this->assertSame(MailStatus::Sent, $log->status);
        $this->assertSame(ReviewMailNotification::class, $log->type);
    }

    public function test_a_queued_notification_for_a_non_mail_channel_is_not_logged(): void
    {
        $notifiable = (new AnonymousNotifiable)->route('mail', 'ann@example.test');

        event(new JobQueued('fake', 'default', '502', new SendQueuedNotifications($notifiable, new ReviewMailNotification, ['database']), '{}', null));

        $this->assertSame(0, MailLog::query()->count());
    }

    public function test_a_redacted_subject_on_the_queued_row(): void
    {
        $mailable = (new WelcomeMail('<p>x</p>', 'Code ?token=QUEUEDSECRET'))->to('ann@example.test');
        $mailable->subject('Code ?token=QUEUEDSECRET');

        event(new JobQueued('fake', 'default', '503', new SendQueuedMailable($mailable), '{}', null));

        $this->assertStringNotContainsString('QUEUEDSECRET', (string) MailLog::query()->sole()->subject);
    }

    public function test_bug_a_job_that_catches_the_transport_exception_leaves_the_row_sending(): void
    {
        // A job that wraps Mail::send() in try/catch (common: "log and continue"). No JobExceptionOccurred fires,
        // JobProcessed calls MailLogContext::endJob() which DISCARDS the in-flight ulids, and no terminating
        // callback was registered (jobId !== null). The row stays `sending` until the daily prune.
        event(new JobProcessing('fake', $this->job('601')));

        try {
            Mail::mailer('failing')->to('ann@example.test')->send(new WelcomeMail);
        } catch (TransportException) {
        }

        event(new JobProcessed('fake', $this->job('601')));

        $this->assertSame(MailStatus::Failed, MailLog::query()->sole()->status);
    }

    public function test_bug_a_sync_job_wipes_the_unconfirmed_messages_of_the_request(): void
    {
        // In a web request: a mail fails and the exception is caught; later a job runs on the `sync` connection.
        // JobProcessing -> MailLogContext::startJob() empties the in-flight list, so the terminating callback finds
        // nothing to fail and the row stays `sending`.
        try {
            Mail::mailer('failing')->to('ann@example.test')->send(new WelcomeMail);
        } catch (TransportException) {
        }

        event(new JobProcessing('sync', $this->job('')));
        event(new JobProcessed('sync', $this->job('')));

        app(LogOutgoingMail::class)->failUnconfirmed();

        $this->assertSame(MailStatus::Failed, MailLog::query()->sole()->status);
    }

    public function test_bug_a_queued_row_whose_job_never_sends_stays_queued_forever(): void
    {
        // Notification::shouldSend() returning false, `deleteWhenMissingModels`, a job that returns early: the
        // worker processes the job, no MessageSending happens. Nothing ever closes the `queued` row, and
        // `mail-log:prune` fails only stale `sending` rows.
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '602', new SendQueuedMailable($mailable), '{}', null));
        event(new JobProcessing('fake', $this->job('602')));
        event(new JobProcessed('fake', $this->job('602')));

        $this->travel(2)->days();
        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertNotSame(MailStatus::Queued, MailLog::query()->sole()->status);
    }

    public function test_bug_a_retryable_exception_fails_the_queued_row_and_the_retry_adds_a_second_row(): void
    {
        // Attempt 1 throws before reaching the mailer (e.g. a model lookup), the job is released and retried with
        // the same id (redis/sqs). The first JobExceptionOccurred already marks the queued row `failed`; the
        // successful retry cannot find a `queued` row and creates another one. The log shows a failed AND a sent
        // copy of one message.
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '603', new SendQueuedMailable($mailable), '{}', null));
        event(new JobProcessing('fake', $this->job('603')));
        event(new JobExceptionOccurred('fake', $this->job('603'), new RuntimeException('deadlock, will retry')));

        event(new JobProcessing('fake', $this->job('603')));
        Mail::mailer('array')->send($mailable);
        event(new JobProcessed('fake', $this->job('603')));

        $this->assertSame(1, MailLog::query()->count());
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    public function test_bug_job_ids_of_different_connections_are_mixed_up(): void
    {
        // Correlation uses the job id only. Database-queue ids are per-table auto-increments, so connection
        // `fake` job 5 and connection `other` job 5 collide: a plain job on `other` that sends a different mail
        // takes over the queued row of the mailable still waiting on `fake`.
        $waiting = (new WelcomeMail('<p>x</p>', 'Waiting'))->to('waiting@example.test');
        event(new JobQueued('fake', 'default', '5', new SendQueuedMailable($waiting), '{}', null));

        event(new JobProcessing('other', $this->job('5')));
        Mail::to('someone-else@example.test')->send(new WelcomeMail('<p>y</p>', 'Unrelated'));
        event(new JobProcessed('other', $this->job('5')));

        $this->assertSame(2, MailLog::query()->count());
        $this->assertSame(MailStatus::Queued, MailLog::query()->where('recipient', 'waiting@example.test')->sole()->status);
    }

    public function test_bug_a_rolled_back_transaction_erases_the_record_of_a_mail_that_was_sent(): void
    {
        // The log writes on the default connection inside the caller's transaction. The mail is already gone when
        // the transaction rolls back, but its audit row disappears with it. Fix: write on a dedicated connection
        // (or document that `connection` must be separate for a reliable audit trail).
        // The suite runs on in-memory SQLite, which can not be cloned into a second connection, so the log is
        // pointed at a separate file database the way `LogConnection` does it for a real default connection.
        $file = tempnam(sys_get_temp_dir(), 'maillog');
        config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        config()->set('filament-mail-log.connection', 'audit');
        (include __DIR__.'/../../database/migrations/create_mail_logs_table.php.stub')->up();

        DB::beginTransaction();
        Mail::to('ann@example.test')->send(new WelcomeMail);
        DB::rollBack();

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(1, MailLog::query()->count());

        DB::purge('audit');
        @unlink($file);
    }

    public function test_the_log_clones_a_file_based_default_connection_and_keeps_in_memory_sqlite(): void
    {
        $this->assertNull(LogConnection::name());

        config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => '/tmp/some.sqlite', 'prefix' => '']);

        $this->assertSame(LogConnection::NAME, LogConnection::name());
        $this->assertSame('/tmp/some.sqlite', config('database.connections.'.LogConnection::NAME.'.database'));

        config()->set('filament-mail-log.isolate_connection', false);
        $this->assertNull(LogConnection::name());
    }

    public function test_a_final_failure_closes_the_queued_row_but_a_retryable_one_does_not(): void
    {
        $mailable = (new WelcomeMail)->to('ann@example.test');
        event(new JobQueued('fake', 'default', '700', new SendQueuedMailable($mailable), '{}', null));

        $job = Mockery::mock(Job::class)->shouldIgnoreMissing([]);
        $job->shouldReceive('getJobId')->andReturn('700');
        $job->shouldReceive('hasFailed')->andReturn(true);

        event(new JobExceptionOccurred('fake', $job, new RuntimeException('boom')));

        $this->assertSame(MailStatus::Failed, MailLog::query()->sole()->status);
    }

    public function test_a_stream_attachment_is_not_consumed_while_measuring(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'stream body');
        rewind($stream);

        $email = (new \Symfony\Component\Mime\Email)
            ->from('a@example.test')->to('b@example.test')->subject('s')->text('t')
            ->addPart(new \Symfony\Component\Mime\Part\DataPart($stream, 'a.txt', 'text/plain'));

        Event::dispatch(new MessageSending($email));

        $this->assertSame('stream body', stream_get_contents($stream));
        $this->assertNull(MailLog::query()->sole()->attachments[0]['size']);
    }

    public function test_a_message_cancelled_by_another_listener_is_reported_as_a_transport_failure(): void
    {
        // Low: a host MessageSending listener returning false cancels the mail after our row was written; the row
        // ends as `failed` with "the transport threw", although nothing was attempted.
        Event::listen(MessageSending::class, fn (): bool => false);

        Mail::to('ann@example.test')->send(new WelcomeMail);
        app(LogOutgoingMail::class)->failUnconfirmed();

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(MailStatus::Failed, MailLog::query()->sole()->status);
    }

    public function test_bug_recipient_search_with_an_underscore_finds_nothing_on_sqlite(): void
    {
        // `contains()` escapes `_` with a backslash, but SQLite's LIKE has no default ESCAPE character, so the
        // pattern becomes a literal backslash. Addresses like `john_doe@...` are common.
        Gate::define('viewMailLog', fn (User $user): bool => true);
        $log = $this->log(['recipient' => 'john_doe@example.test', 'recipients' => ['to' => ['john_doe@example.test'], 'cc' => [], 'bcc' => []]]);

        Livewire::test(ListMailLogs::class)
            ->filterTable('recipient', ['recipient' => 'john_doe'])
            ->assertCanSeeTableRecords([$log]);
    }

    public function test_hostile_headers_attachment_names_and_error_are_escaped_on_the_view_page(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
        $log = $this->log([
            'status' => MailStatus::Failed,
            'subject' => '<img src=x onerror=alert(1)>',
            'error' => '<script>alert(2)</script>',
            'sender' => '<svg onload=alert(3)>',
            'attachments' => [['name' => '<script>alert(4)</script>.pdf', 'mime' => 'application/pdf', 'size' => 1]],
            'headers' => ['X-Evil' => ['<script>alert(5)</script>']],
            'type' => '<script>alert(6)</script>',
        ]);

        $html = Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])->html();

        foreach (['<img src=x onerror', '<script>alert(2)', '<svg onload', '<script>alert(4)', '<script>alert(5)', '<script>alert(6)'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
    }

    public function test_hostile_subject_and_type_are_escaped_in_the_list(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
        $this->log(['subject' => '<img src=x onerror=alert(1)>', 'type' => '<script>alert(2)</script>']);

        $html = Livewire::test(ListMailLogs::class)->html();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
    }

    public function test_a_user_without_access_can_not_mount_the_view_page_through_livewire(): void
    {
        $log = $this->log();

        Livewire::test(ViewMailLog::class, ['record' => $log->getRouteKey()])->assertForbidden();
    }

    public function test_a_user_without_access_can_not_mount_the_list_through_livewire(): void
    {
        Livewire::test(ListMailLogs::class)->assertForbidden();
    }

    public function test_prune_keeps_queued_rows_of_the_retention_window(): void
    {
        $fresh = $this->log(['status' => MailStatus::Queued]);
        $old = $this->log(['created_at' => now()->subDays(100)]);

        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertTrue(MailLog::query()->whereKey($fresh->id)->exists());
        $this->assertFalse(MailLog::query()->whereKey($old->id)->exists());
    }

    public function test_prune_with_a_non_numeric_days_option_falls_back_to_the_config(): void
    {
        $old = $this->log(['created_at' => now()->subDays(100)]);

        $this->artisan('mail-log:prune', ['--days' => 'abc'])->assertSuccessful();

        $this->assertFalse(MailLog::query()->whereKey($old->id)->exists());
    }

    private function job(string $id): Job
    {
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing([]);
        $job->shouldReceive('getJobId')->andReturn($id);

        return $job;
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

class ReviewMailNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Review notification')->line('Hello');
    }
}
