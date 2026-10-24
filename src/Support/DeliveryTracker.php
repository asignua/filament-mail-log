<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Enums\SuppressionReason;
use Asignua\FilamentMailLog\Repositories\DeliveryRepository;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;

/**
 * Applies a provider event: find the message, store the event once, move the status forward, suppress the address.
 * Cheap on purpose (a few indexed queries), because it runs inside the webhook request.
 */
final class DeliveryTracker
{
    public function __construct(private readonly DeliveryRepository $repository) {}

    /**
     * @return bool whether the event was tied to a message of the log
     */
    public function record(DeliveryEvent $event): bool
    {
        $log = $this->repository->findLog($event);

        if ($log !== null && $this->repository->storeEvent($log, $event)) {
            $this->repository->advanceStatus($log, $event);
        }

        // The provider confirmed that the ADDRESS is dead, whether or not the message is in this log
        // (another application may share the provider account). Idempotent, so a redelivered event is harmless.
        if ($event->shouldSuppress() && $event->recipient !== null && (bool) config('filament-mail-log.suppression.record', true) && TrackingSchema::hasSuppressionsTable()) {
            $this->repository->suppress(
                $event->recipient,
                $event->type === DeliveryEventType::Complained ? SuppressionReason::Complaint : SuppressionReason::HardBounce,
                $event->provider,
                $log === null ? null : (int) $log->getKey(),
                $event->detail,
            );
        }

        return $log !== null;
    }
}
