<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

class PruneTest extends TestCase
{
    public function test_old_records_go_and_recent_ones_stay(): void
    {
        config()->set('filament-mail-log.retention_days', 30);
        $old = $this->log(daysAgo: 31);
        $recent = $this->log(daysAgo: 29);

        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertNull(MailLog::query()->find($old->id));
        $this->assertNotNull(MailLog::query()->find($recent->id));
    }

    public function test_the_days_option_overrides_the_config(): void
    {
        $this->log(daysAgo: 8);
        $kept = $this->log(daysAgo: 2);

        $this->artisan('mail-log:prune', ['--days' => 5])->assertSuccessful();

        $this->assertSame([$kept->id], MailLog::query()->pluck('id')->all());
    }

    public function test_zero_days_turns_the_rotation_off(): void
    {
        config()->set('filament-mail-log.retention_days', 0);
        $this->log(daysAgo: 4000);

        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertSame(1, MailLog::query()->count());
    }

    public function test_more_than_one_chunk_is_deleted(): void
    {
        foreach (range(1, 450) as $i) {
            $this->log(daysAgo: 400);
        }

        $this->artisan('mail-log:prune', ['--days' => 10])->assertSuccessful();

        $this->assertSame(0, MailLog::query()->count());
    }

    public function test_a_stale_sending_row_is_failed_and_a_fresh_one_is_left(): void
    {
        $stale = $this->log(daysAgo: 0, status: MailStatus::Sending);
        $stale->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $fresh = $this->log(daysAgo: 0, status: MailStatus::Sending);

        $this->artisan('mail-log:prune')->assertSuccessful();

        $this->assertSame(MailStatus::Failed, $stale->refresh()->status);
        $this->assertSame(MailStatus::Sending, $fresh->refresh()->status);
    }

    public function test_the_command_is_scheduled_daily_by_default(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event): bool => str_contains((string) $event->command, 'mail-log:prune'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('40 3 * * *', $events->first()->expression);
    }

    private function log(int $daysAgo, MailStatus $status = MailStatus::Sent): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = $status;
        $log->recipient = 'a@example.test';
        $log->created_at = now()->subDays($daysAgo);
        $log->updated_at = now()->subDays($daysAgo);
        $log->save();

        return $log;
    }
}
