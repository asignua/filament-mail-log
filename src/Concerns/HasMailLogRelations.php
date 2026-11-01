<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Concerns;

use Asignua\FilamentMailLog\Support\MailRelations;

/**
 * For a Mailable. Implement `mailLogRelations()` (or call `->relatedTo($order)`) and the sent message is linked to
 * those models:
 *
 *     class OrderShipped extends Mailable implements RelatesToMailLog
 *     {
 *         use HasMailLogRelations;
 *
 *         public function __construct(public Order $order) {}
 *
 *         public function mailLogRelations(): iterable { return [$this->order, $this->order->customer]; }
 *     }
 *
 * The mail events do not carry the Mailable itself, so the models travel in the view data (like Laravel's own
 * `__laravel_mailable`) under the key {@see MailRelations::DATA_KEY}. They never reach the message that is sent.
 */
trait HasMailLogRelations
{
    use RelatesToModels;

    /**
     * @return array<string, mixed>
     */
    protected function additionalMessageData(): array
    {
        return array_merge(parent::additionalMessageData(), [
            MailRelations::DATA_KEY => MailRelations::models($this->mailLogRelations()),
        ]);
    }
}
