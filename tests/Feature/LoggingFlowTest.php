<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Listeners\LogOutgoingMail;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Tests\Fixtures\FailingTransport;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;

class LoggingFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MailLogContext::reset();
    }

    public function test_a_sent_message_is_logged_with_its_details(): void
    {
        Mail::to(['ann@example.test'])
            ->cc('cc@example.test')
            ->bcc('bcc@example.test')
            ->send(new WelcomeMail('<p>Hi <b>Ann</b></p>', 'Hello Ann'));

        $log = MailLog::query()->sole();

        $this->assertSame(MailStatus::Sent, $log->status);
        $this->assertSame('Hello Ann', $log->subject);
        $this->assertSame('ann@example.test', $log->recipient);
        $this->assertSame(['ann@example.test'], $log->recipients['to']);
        $this->assertSame(['cc@example.test'], $log->recipients['cc']);
        $this->assertSame(['bcc@example.test'], $log->recipients['bcc']);
        $this->assertSame(WelcomeMail::class, $log->type);
        $this->assertSame('array', $log->mailer);
        $this->assertStringContainsString('Ann', (string) $log->html_body);
        $this->assertSame('filament-mail-log-fixture-text', trim((string) $log->text_body));
        $this->assertNotNull($log->sent_at);
        $this->assertNotNull($log->message_id);
        $this->assertNull($log->error);
        $this->assertFalse($log->redacted);
        $this->assertSame(26, strlen($log->ulid));
    }

    public function test_the_message_is_still_delivered(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_the_correlation_header_reaches_the_message_and_is_not_stored_in_the_headers_tab(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail);

        $log = MailLog::query()->sole();
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first();

        $this->assertSame($log->ulid, $sent->getOriginalMessage()->getHeaders()->get('X-Mail-Log-Id')?->getBodyAsString());
        $this->assertArrayNotHasKey('X-Mail-Log-Id', $log->headers ?? []);
        $this->assertArrayHasKey('Subject', $log->headers ?? []);
    }

    public function test_secrets_are_redacted_before_they_are_stored(): void
    {
        $html = '<a href="https://x.test/verify?expires=1&amp;signature=SIGSECRET">Verify</a>'
            .'<a href="https://x.test/reset-password/TOKENSECRET?email=a">Reset</a> Password: hunter2';

        Mail::to('ann@example.test')->send(new WelcomeMail($html, 'Code https://x.test/?token=SUBJSECRET'));

        $log = MailLog::query()->sole();
        $stored = json_encode([$log->html_body, $log->text_body, $log->subject, $log->headers]);

        foreach (['SIGSECRET', 'TOKENSECRET', 'hunter2', 'SUBJSECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $stored);
        }

        $this->assertTrue($log->redacted);
    }

    public function test_the_original_message_is_not_altered_by_redaction(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail('<a href="https://x.test/?signature=KEEPME">x</a>'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->first();

        $this->assertStringContainsString('KEEPME', (string) $sent->getOriginalMessage()->getHtmlBody());
    }

    public function test_attachments_store_metadata_but_never_the_content(): void
    {
        Mail::send([], [], function ($message): void {
            $message->to('ann@example.test')->subject('Report')->text('see attached');
            $message->attachData('TOP-SECRET-CONTENT', 'report.txt', ['mime' => 'text/plain']);
        });

        $log = MailLog::query()->sole();

        $this->assertSame([['name' => 'report.txt', 'mime' => 'text/plain', 'size' => 18]], $log->attachments);
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', (string) json_encode($log->getAttributes()));
    }

    public function test_attachment_sizes_can_be_switched_off(): void
    {
        config()->set('filament-mail-log.attachments.sizes', false);

        Mail::send([], [], function ($message): void {
            $message->to('ann@example.test')->subject('Report')->text('x');
            $message->attachData('abc', 'a.txt', ['mime' => 'text/plain']);
        });

        $this->assertNull(MailLog::query()->sole()->attachments[0]['size']);
    }

    public function test_ignored_headers_are_not_stored(): void
    {
        Mail::send([], [], function ($message): void {
            $message->to('ann@example.test')->subject('H')->text('x');
            $message->getHeaders()->addTextHeader('Authorization', 'Bearer LEAK');
            $message->getHeaders()->addTextHeader('X-Custom', 'visible');
        });

        $headers = MailLog::query()->sole()->headers ?? [];

        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertSame(['visible'], $headers['X-Custom']);
    }

    public function test_the_body_can_be_left_out_entirely(): void
    {
        config()->set('filament-mail-log.body.store', false);

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $log = MailLog::query()->sole();

        $this->assertNull($log->html_body);
        $this->assertNull($log->text_body);
        $this->assertSame(MailStatus::Sent, $log->status);
    }

    public function test_a_long_body_is_cut_to_the_configured_size(): void
    {
        config()->set('filament-mail-log.body.max_bytes', 100);

        Mail::to('ann@example.test')->send(new WelcomeMail('<p>'.str_repeat('é', 500).'</p>'));

        $log = MailLog::query()->sole();

        $this->assertLessThanOrEqual(100, strlen((string) $log->html_body));
        $this->assertTrue($log->truncated);
        $this->assertTrue(mb_check_encoding((string) $log->html_body, 'UTF-8'));
    }

    public function test_unicode_stays_readable_in_the_database(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail('<p>x</p>', 'Привіт'));

        $raw = MailLog::query()->toBase()->first();

        $this->assertStringContainsString('ann@example.test', (string) $raw->recipients);
        $this->assertSame('Привіт', MailLog::query()->sole()->subject);
    }

    public function test_nothing_is_logged_when_disabled(): void
    {
        config()->set('filament-mail-log.enabled', false);

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertSame(0, MailLog::query()->count());
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_a_broken_log_table_never_stops_the_mail(): void
    {
        config()->set('filament-mail-log.table', 'table_that_does_not_exist');

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_a_transport_failure_leaves_a_sending_row_that_the_end_of_the_process_fails(): void
    {
        config()->set('mail.mailers.failing', ['transport' => 'failing']);
        Mail::extend('failing', fn (): FailingTransport => new FailingTransport);

        try {
            Mail::mailer('failing')->to('ann@example.test')->send(new WelcomeMail);
            $this->fail('The transport should have thrown.');
        } catch (TransportException) {
        }

        $this->assertSame(MailStatus::Sending, MailLog::query()->sole()->status);

        app(LogOutgoingMail::class)->failUnconfirmed();

        $log = MailLog::query()->sole();

        $this->assertSame(MailStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_at);
        $this->assertNotEmpty($log->error);
    }

    public function test_a_message_that_was_sent_is_not_failed_by_the_end_of_the_process(): void
    {
        Mail::to('ann@example.test')->send(new WelcomeMail);

        app(LogOutgoingMail::class)->failUnconfirmed();

        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    public function test_a_queued_mailable_gets_a_queued_row_that_the_worker_continues(): void
    {
        $mailable = (new WelcomeMail('<p>Hi</p>', 'Queued hello'))->to('ann@example.test');

        event(new JobQueued('fake', 'default', '42', new SendQueuedMailable($mailable), '{}', null));

        $queued = MailLog::query()->sole();
        $this->assertSame(MailStatus::Queued, $queued->status);
        $this->assertSame('42', $queued->job_id);
        $this->assertSame('ann@example.test', $queued->recipient);
        $this->assertSame(WelcomeMail::class, $queued->type);
        $this->assertNotNull($queued->queued_at);

        event(new JobProcessing('fake', $this->job('42')));
        Mail::mailer('array')->send($mailable);
        event(new JobProcessed('fake', $this->job('42')));

        $log = MailLog::query()->sole();

        $this->assertSame($queued->id, $log->id);
        $this->assertSame(MailStatus::Sent, $log->status);
        $this->assertNotNull($log->queued_at);
        $this->assertNotNull($log->sent_at);
    }

    public function test_the_sync_driver_does_not_create_a_queued_row(): void
    {
        config()->set('queue.connections.inline', ['driver' => 'sync']);
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('inline', 'default', '1', new SendQueuedMailable($mailable), '{}', null));

        $this->assertSame(0, MailLog::query()->count());
    }

    public function test_a_queue_failure_after_the_mailer_started_fails_the_row(): void
    {
        config()->set('mail.mailers.failing', ['transport' => 'failing']);
        Mail::extend('failing', fn (): FailingTransport => new FailingTransport);
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '7', new SendQueuedMailable($mailable), '{}', null));
        event(new JobProcessing('fake', $this->job('7')));

        try {
            Mail::mailer('failing')->send($mailable);
        } catch (TransportException $e) {
            event(new JobExceptionOccurred('fake', $this->job('7'), $e));
        }

        $log = MailLog::query()->sole();

        $this->assertSame(MailStatus::Failed, $log->status);
        $this->assertStringContainsString('SMTP said no', (string) $log->error);
        $this->assertStringNotContainsString('hunter2', (string) $log->error);
        $this->assertNotNull($log->failed_at);
    }

    public function test_a_job_that_dies_before_sending_fails_its_queued_row(): void
    {
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '9', new SendQueuedMailable($mailable), '{}', null));
        event(new JobFailed('fake', $this->job('9'), new RuntimeException('Serialization broke')));

        $log = MailLog::query()->sole();

        $this->assertSame(MailStatus::Failed, $log->status);
        $this->assertSame('Serialization broke', $log->error);
    }

    public function test_a_failure_of_one_job_does_not_touch_a_message_of_the_next(): void
    {
        event(new JobProcessing('fake', $this->job('1')));
        Mail::to('first@example.test')->send(new WelcomeMail);
        event(new JobProcessed('fake', $this->job('1')));

        event(new JobProcessing('fake', $this->job('2')));
        event(new JobExceptionOccurred('fake', $this->job('2'), new RuntimeException('boom')));

        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    private function job(string $id): Job
    {
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing([]);
        $job->shouldReceive('getJobId')->andReturn($id);

        return $job;
    }
}
