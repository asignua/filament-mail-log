<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Tests\TestCase;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Independent verification (round 3): the retry fixes against the REAL database queue driver and worker,
 * not mocked Job objects.
 */
class VerifierRealDatabaseQueueTest extends TestCase
{
    public static int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        MailLogContext::reset();
        self::$attempts = 0;

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        config()->set('queue.default', 'fake');
        config()->set('queue.connections.fake', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    }

    public function test_a_job_that_throws_once_and_is_retried_on_the_database_driver_leaves_one_sent_row(): void
    {
        (new AnonymousNotifiable)->route('mail', 'ann@example.test')->notify(new VerifierThrowOnceNotification);

        $this->artisan('queue:work', ['connection' => 'fake', '--once' => true, '--tries' => 2]);
        $this->artisan('queue:work', ['connection' => 'fake', '--once' => true, '--tries' => 2]);

        $this->assertSame(2, self::$attempts);
        $this->assertSame(1, MailLog::query()->count(), 'rows: '.MailLog::query()->pluck('status')->map->value->implode(','));
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    public function test_a_job_released_by_middleware_on_the_database_driver_leaves_one_sent_row(): void
    {
        (new AnonymousNotifiable)->route('mail', 'ann@example.test')->notify(new VerifierReleasedOnceNotification);

        $this->artisan('queue:work', ['connection' => 'fake', '--once' => true, '--tries' => 3]);
        $this->artisan('queue:work', ['connection' => 'fake', '--once' => true, '--tries' => 3]);

        $this->assertSame(2, self::$attempts);
        $this->assertSame(1, MailLog::query()->count(), 'rows: '.MailLog::query()->pluck('status')->map->value->implode(','));
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }
}

class VerifierThrowOnceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        VerifierRealDatabaseQueueTest::$attempts++;

        if (VerifierRealDatabaseQueueTest::$attempts === 1) {
            throw new RuntimeException('deadlock, will retry');
        }

        return (new MailMessage)->subject('Hi')->line('Hello');
    }
}

class VerifierReleasedOnceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new VerifierReleaseOnceMiddleware];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Hi')->line('Hello');
    }
}

class VerifierReleaseOnceMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        VerifierRealDatabaseQueueTest::$attempts++;

        if (VerifierRealDatabaseQueueTest::$attempts === 1) {
            $job->release(0);

            return null;
        }

        return $next($job);
    }
}
