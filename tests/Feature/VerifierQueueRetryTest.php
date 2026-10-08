<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;

/**
 * Independent verification (08.10.2026, round 2): retry paths the round-1 fixes do not cover.
 * `test_bug_*` FAIL until fixed.
 */
class VerifierQueueRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MailLogContext::reset();
    }

    public function test_bug_a_released_job_is_reported_as_finished_without_sending_and_the_retry_adds_a_second_row(): void
    {
        // The Worker fires JobProcessed also for a job that RELEASED itself (rate limiting / ThrottlesExceptions /
        // WithoutOverlapping middleware). jobProcessed() then fails the `queued` row with "finished without
        // sending"; the retry (same id on redis / sqs) finds no queued row and creates another one.
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '801', new SendQueuedMailable($mailable), '{}', null));
        event(new JobProcessing('fake', $this->job('801', released: true)));
        event(new JobProcessed('fake', $this->job('801', released: true)));

        event(new JobProcessing('fake', $this->job('801')));
        Mail::mailer('array')->send($mailable);
        event(new JobProcessed('fake', $this->job('801')));

        $this->assertSame(1, MailLog::query()->count());
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    public function test_bug_on_the_database_driver_a_retried_job_leaves_its_queued_row_queued_forever(): void
    {
        // The database queue RE-INSERTS a released job, so the retry has a NEW id. Attempt 1 throws before the
        // mailer (retryable, so the queued row is kept), attempt 2 (new id) sends and creates its own row.
        // The first row is never closed: failStale() only handles `sending`.
        $mailable = (new WelcomeMail)->to('ann@example.test');

        event(new JobQueued('fake', 'default', '901', new SendQueuedMailable($mailable), '{"uuid":"uuid-1"}', null));
        event(new JobProcessing('fake', $this->job('901', uuid: 'uuid-1')));
        event(new JobExceptionOccurred('fake', $this->job('901', uuid: 'uuid-1'), new RuntimeException('deadlock, will retry')));

        event(new JobProcessing('fake', $this->job('902', uuid: 'uuid-1')));
        Mail::mailer('array')->send($mailable);
        event(new JobProcessed('fake', $this->job('902', uuid: 'uuid-1')));

        $this->travel(2)->days();
        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertSame(0, MailLog::query()->where('status', MailStatus::Queued->value)->count());
    }

    private function job(string $id, bool $released = false, ?string $uuid = null): Job
    {
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing([]);
        $job->shouldReceive('getJobId')->andReturn($id);
        $job->shouldReceive('isReleased')->andReturn($released);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('hasFailed')->andReturn(false);

        return $job;
    }
}
