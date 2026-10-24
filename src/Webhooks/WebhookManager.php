<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks;

use Asignua\FilamentMailLog\Webhooks\Contracts\WebhookDriver;

/**
 * Builds the driver of a provider from `filament-mail-log.webhooks.providers`.
 */
final class WebhookManager
{
    public function driver(string $provider): ?WebhookDriver
    {
        $providers = config('filament-mail-log.webhooks.providers', []);
        $block = is_array($providers) ? ($providers[$provider] ?? null) : null;

        if (!is_array($block) || !is_string($block['driver'] ?? null) || !is_a($block['driver'], WebhookDriver::class, true)) {
            return null;
        }

        /** @var WebhookDriver */
        return app()->make($block['driver'], ['name' => $provider, 'config' => $block]);
    }
}
