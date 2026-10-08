<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleOptOutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mail-log.schedule.enabled', false);
    }

    public function test_the_schedule_can_be_switched_off(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event): bool => str_contains((string) $event->command, 'mail-log:prune'),
        );

        $this->assertCount(0, $events);
    }
}
