<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog;

use Asignua\FilamentMailLog\Commands\PruneCommand;
use Asignua\FilamentMailLog\Listeners\LogOutgoingMail;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MailLogServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mail-log';

    public function configurePackage(Package $package): void
    {
        // Translations: `__('filament-mail-log::filament-mail-log.<key>')`, publish tag `filament-mail-log-translations`.
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_mail_logs_table')
            ->hasCommand(PruneCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MailLogRepository::class);
        $this->app->singleton(LogOutgoingMail::class);
    }

    public function packageBooted(): void
    {
        // A fresh application (tests, Octane worker boot) starts with an empty registry.
        MailLogContext::reset();

        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $events): void {
            $events->listen(MessageSending::class, [LogOutgoingMail::class, 'sending']);
            $events->listen(MessageSent::class, [LogOutgoingMail::class, 'sent']);
            $events->listen(JobQueued::class, [LogOutgoingMail::class, 'queued']);
            $events->listen(JobProcessing::class, [LogOutgoingMail::class, 'jobProcessing']);
            $events->listen(JobProcessed::class, [LogOutgoingMail::class, 'jobProcessed']);
            $events->listen(JobExceptionOccurred::class, [LogOutgoingMail::class, 'jobExceptionOccurred']);
            $events->listen(JobFailed::class, [LogOutgoingMail::class, 'jobFailed']);
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (!(bool) config('filament-mail-log.schedule.enabled', true)) {
                return;
            }

            $time = config('filament-mail-log.schedule.time', '03:40');

            $schedule->command('mail-log:prune')->dailyAt(is_string($time) ? $time : '03:40');
        });
    }
}
