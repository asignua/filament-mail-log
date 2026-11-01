<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Repositories;

use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogRelation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queries of the links between messages and models.
 */
class MailLogRelationRepository
{
    /**
     * @return Builder<MailLogRelation>
     */
    public function query(): Builder
    {
        return MailLogRelation::query();
    }

    /**
     * Links the message to the models, given as `[morph class, key]` pairs (see {@see \Asignua\FilamentMailLog\Support\MailRelations}).
     * Idempotent: the same pair is stored once (the message is described at the queue and again when the worker
     * sends it). A key the column can not hold (a UUID with `key_type` = int) is skipped.
     *
     * @param iterable<array{0: string, 1: int|string}> $refs
     */
    public function attach(MailLog $log, iterable $refs): void
    {
        $rows = [];

        foreach ($refs as [$type, $key]) {
            $key = MailLogRelation::normalizeKey($key);

            if ($key !== null) {
                $rows[] = ['mail_log_id' => (int) $log->getKey(), 'related_type' => mb_substr($type, 0, 120), 'related_id' => $key];
            }
        }

        if ($rows !== []) {
            $this->query()->insertOrIgnore($rows);
        }
    }

    /**
     * Gives a resend the links of the message it repeats.
     */
    public function copy(int $fromId, MailLog $to): void
    {
        $rows = $this->query()->where('mail_log_id', $fromId)->get(['related_type', 'related_id'])
            ->map(fn (MailLogRelation $row): array => [
                'mail_log_id' => (int) $to->getKey(),
                'related_type' => $row->related_type,
                'related_id' => $row->related_id,
            ])->all();

        if ($rows !== []) {
            $this->query()->insertOrIgnore($rows);
        }
    }

    /**
     * Deletes the links of the given log rows. The foreign key does it on MySQL / PostgreSQL; this covers a
     * database that does not enforce it (SQLite without foreign keys).
     *
     * @param list<int> $logIds
     */
    public function deleteOf(array $logIds): int
    {
        return $logIds === [] ? 0 : $this->query()->whereIn('mail_log_id', $logIds)->delete();
    }

    /**
     * @return list<string>
     */
    public function distinctTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->query()->distinct()->orderBy('related_type')->pluck('related_type')->all();

        return $types;
    }
}
