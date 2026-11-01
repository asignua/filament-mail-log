<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Jobs;

use Asignua\FilamentMailLog\Support\BounceAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one bounce alert. The webhook only enqueues it: looking up the users the gate lets in and sending mail
 * are slow, and the webhook request must answer the provider fast (a provider retries on a timeout).
 * Primitives only, so the payload carries no model and no personal data beyond the alert text itself.
 */
final class DeliverBounceAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One attempt: a retry after a partial send would notify twice. */
    public int $tries = 1;

    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines,
        public readonly ?int $mailLogId,
    ) {}

    public function handle(BounceAlerts $alerts): void
    {
        $alerts->deliver($this->title, $this->lines, $this->mailLogId);
    }
}
