<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Repositories;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every query of the plugin that is not a Filament table hook.
 */
class MailLogRepository
{
    /** Rows per DELETE: a row can hold two bodies, one big DELETE would hold locks for long. */
    public const int PRUNE_CHUNK = 200;

    /**
     * @return Builder<MailLog>
     */
    public function query(): Builder
    {
        return MailLog::query();
    }

    public function findQueuedByJob(string $jobId): ?MailLog
    {
        return $this->query()
            ->where('job_id', $jobId)
            ->where('status', MailStatus::Queued->value)
            ->latest('id')
            ->first();
    }

    public function markSent(string $ulid, ?string $messageId): void
    {
        $this->query()->where('ulid', $ulid)->update([
            'status' => MailStatus::Sent->value,
            'message_id' => $messageId,
            'error' => null,
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param list<string> $ulids
     */
    public function markFailedIfSending(array $ulids, string $error): int
    {
        if ($ulids === []) {
            return 0;
        }

        return $this->fail($this->query()->whereIn('ulid', $ulids)->where('status', MailStatus::Sending->value), $error);
    }

    /**
     * A job that died before it reached the mailer leaves its `queued` row behind.
     */
    public function markFailedIfQueued(string $jobId, string $error): int
    {
        return $this->fail($this->query()->where('job_id', $jobId)->where('status', MailStatus::Queued->value), $error);
    }

    /**
     * `sending` rows nobody confirmed for a long time: the sending process is gone.
     */
    public function failStale(Carbon $before): int
    {
        return $this->fail(
            $this->query()->where('status', MailStatus::Sending->value)->where('updated_at', '<', $before),
            'No delivery confirmation: the sending process ended before the transport answered.',
        );
    }

    /**
     * Deletes records created before $cutoff in chunks, returns how many went.
     */
    public function prune(Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            /** @var list<int> $ids */
            $ids = $this->query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::PRUNE_CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += $this->query()->whereIn('id', $ids)->delete();
        } while (count($ids) === self::PRUNE_CHUNK);

        return $deleted;
    }

    /**
     * @return list<string>
     */
    public function distinctTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->all();

        return $types;
    }

    /**
     * @param Builder<MailLog> $builder
     */
    private function fail(Builder $builder, string $error): int
    {
        return $builder->update([
            'status' => MailStatus::Failed->value,
            'error' => MailRedactor::clean(mb_strimwidth($error, 0, 1000, '…')),
            'failed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
