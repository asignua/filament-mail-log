<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Concerns;

use Asignua\FilamentMailLog\Support\MailRelations;
use Illuminate\Database\Eloquent\Model;

/**
 * Storage for {@see \Asignua\FilamentMailLog\Contracts\RelatesToMailLog}: `->relatedTo($order, $user)`. Usable on a
 * Notification as is; a Mailable takes {@see HasMailLogRelations}, which adds the wiring a Mailable needs.
 *
 * Only the `[morph class, key]` pair of each model is kept, so a queued payload carries no attributes (personal data)
 * and a model deleted before the job runs is not resurrected. Override `mailLogRelations()` for models you compute
 * yourself; `relatedTo()` pairs are added to them.
 */
trait RelatesToModels
{
    /** @var list<array{0: string, 1: int|string}> */
    protected array $mailLogRelated = [];

    /**
     * @param iterable<Model>|Model ...$models
     */
    public function relatedTo(Model|iterable ...$models): static
    {
        foreach ($models as $item) {
            foreach ($item instanceof Model ? [$item] : $item as $model) {
                $ref = MailRelations::refOf($model);

                if ($ref !== null) {
                    $this->mailLogRelated[] = $ref;
                }
            }
        }

        return $this;
    }

    /**
     * Models of your own; empty by default (the `relatedTo()` pairs are read through `mailLogRelatedRefs()`).
     *
     * @return iterable<Model>
     */
    public function mailLogRelations(): iterable
    {
        return [];
    }

    /**
     * @return list<array{0: string, 1: int|string}>
     */
    public function mailLogRelatedRefs(): array
    {
        return $this->mailLogRelated;
    }
}
