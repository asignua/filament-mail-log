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
            Log::info("filament-mail-log: webhook [{$provider}] refused: ".$e->getMessage());

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        try {
            $matched = 0;

            foreach ($events as $event) {
                $matched += $tracker->record($event) ? 1 : 0;
            }
        } catch (Throwable $e) {
            // A 5xx makes the provider retry; the unique key makes the retry safe.
            report($e);

            return response()->json(['message' => 'Could not record the events.'], 500);
        }

        return response()->json(['received' => count($events), 'matched' => $matched]);
    }
}
