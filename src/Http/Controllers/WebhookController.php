<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Http\Controllers;

use Asignua\FilamentMailLog\Support\DeliveryTracker;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Asignua\FilamentMailLog\Webhooks\WebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST {path}/{provider}. Processed in the request, not queued: a webhook is a handful of indexed queries,
 * the provider retries on any non-2xx (so a failure here loses nothing), and queuing would make delivery
 * tracking depend on a worker that the log itself does not otherwise need.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $provider, WebhookManager $webhooks, DeliveryTracker $tracker): JsonResponse
    {
        $driver = $webhooks->driver($provider);

        if ($driver === null || !$driver->enabled()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $driver->verify($request);
            $events = $driver->events($request);
        } catch (InvalidWebhook $e) {
            Log::debug("filament-mail-log: webhook [{$provider}] refused: ".$e->getMessage());

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        try {
            $matched = 0;
            $early = false;
            $window = (int) config('filament-mail-log.webhooks.retry_unmatched_seconds', 60);

            foreach ($events as $event) {
                if ($tracker->record($event)) {
                    $matched++;
                } elseif ($window > 0 && $event->occurredAt->getTimestamp() >= time() - $window) {
                    $early = true;
                }
            }
        } catch (Throwable $e) {
            // A 5xx makes the provider retry; the unique key makes the retry safe.
            report($e);

            return response()->json(['message' => 'Could not record the events.'], 500);
        }

        // A fresh event nobody can match yet most likely raced the transport answer (the row has no message id until
        // `sent` is recorded): ask the provider to deliver it again. Old unmatched events are dropped with a 200 so
        // that mail from other applications on the same account does not retry forever.
        if ($early) {
            return response()->json(['received' => count($events), 'matched' => $matched, 'message' => 'Not matched yet, retry.'], 503, ['Retry-After' => '30']);
        }

        return response()->json(['received' => count($events), 'matched' => $matched]);
    }
}
