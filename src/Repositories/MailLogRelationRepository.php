<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Repositories;

use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
     * Links the message to the models. Idempotent: the same pair is stored once (the message is described at the
     * queue and again when the worker sends it).
     *
     * @param iterable<Model> $models
     */
    public function attach(MailLog $log, iterable $models): void
    {
        $rows = [];

        foreach ($models as $model) {
            $rows[] = [
                'mail_log_id' => (int) $log->getKey(),
                'related_type' => mb_substr($model->getMorphClass(), 0, 120),
                'related_id' => mb_substr((string) $model->getKey(), 0, 64),
            ];
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
