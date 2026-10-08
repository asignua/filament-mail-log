<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Commands;

use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'mail-log:prune {--days= : Keep this many days instead of filament-mail-log.retention_days}';

    protected $description = 'Delete mail log records older than the retention period and fail stale "sending" rows';

    public function handle(MailLogRepository $repository): int
    {
        $stale = $repository->failStale(now()->subMinutes(max(1, (int) config('filament-mail-log.stale_after_minutes', 30))));

        if ($stale > 0) {
            $this->components->warn("Marked {$stale} unconfirmed message(s) as failed.");
        }

        $queuedMinutes = (int) config('filament-mail-log.queued_stale_after_minutes', 4320);

        if ($queuedMinutes > 0) {
            $lost = $repository->failStaleQueued(now()->subMinutes($queuedMinutes));

            if ($lost > 0) {
                $this->components->warn("Marked {$lost} long-queued message(s) as failed.");
            }
        }

        $option = $this->option('days');
        $days = (int) (is_numeric($option) ? $option : config('filament-mail-log.retention_days', 90));

        if ($days <= 0) {
            $this->components->info('Retention is off (days = 0), nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = $repository->prune(now()->subDays($days));

        $this->components->info("Deleted {$deleted} mail log record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
