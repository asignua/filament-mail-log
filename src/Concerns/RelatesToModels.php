<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Storage for {@see \Asignua\FilamentMailLog\Contracts\RelatesToMailLog}: `->relatedTo($order, $user)`. Usable on a
 * Notification as is; a Mailable takes {@see HasMailLogRelations}, which adds the wiring a Mailable needs.
 */
trait RelatesToModels
{
    /** @var list<Model> */
    protected array $mailLogRelated = [];

    /**
     * @param iterable<Model>|Model ...$models
     */
    public function relatedTo(Model|iterable ...$models): static
    {
        foreach ($models as $item) {
            foreach ($item instanceof Model ? [$item] : $item as $model) {
                $this->mailLogRelated[] = $model;
            }
        }

        return $this;
    }

    /**
     * Override it to compute the models yourself; call `parent::` or add `$this->mailLogRelated` to keep `relatedTo()`.
     *
     * @return iterable<Model>
     */
    public function mailLogRelations(): iterable
    {
        return $this->mailLogRelated;
    }
}
