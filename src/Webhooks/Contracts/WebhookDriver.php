<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Contracts;

use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Illuminate\Http\Request;

/**
 * One mail provider. The controller calls {@see self::verify()} first and only then {@see self::events()}, so
 * `events()` may trust the request.
 */
interface WebhookDriver
{
    /**
     * Whether the provider is switched on in the config. A webhook for a provider that is off answers 404.
     */
    public function enabled(): bool;

    /**
     * Proves that the request comes from the provider (signature / credentials) and is fresh.
     * An enabled provider whose secret is not filled in refuses everything.
     *
     * @throws InvalidWebhook
     */
    public function verify(Request $request): void;

    /**
     * The delivery events of a verified request. Events the plugin does not track (sent, accepted, unsubscribed...)
     * are simply left out. A driver may also answer a handshake here (Amazon SNS subscription confirmation).
     *
     * @throws InvalidWebhook
     *
     * @return list<DeliveryEvent>
     */
    public function events(Request $request): array;
}
