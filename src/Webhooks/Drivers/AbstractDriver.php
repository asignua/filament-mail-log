<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks\Drivers;

use Asignua\FilamentMailLog\Webhooks\Contracts\WebhookDriver;
use Asignua\FilamentMailLog\Webhooks\InvalidWebhook;
use Asignua\FilamentMailLog\Webhooks\PayloadSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

abstract class AbstractDriver implements WebhookDriver
{
    /**
     * @param array<string, mixed> $config the provider's block of `filament-mail-log.webhooks.providers`
     */
    public function __construct(protected readonly string $name, protected readonly array $config = []) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    protected function option(string $key): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function json(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            throw InvalidWebhook::malformed('The body is not JSON.');
        }

        return $data;
    }

    /**
     * Replay protection: a signed timestamp far from now is refused.
     */
    protected function assertFresh(int $timestamp): void
    {
        $tolerance = $this->config['tolerance'] ?? config('filament-mail-log.webhooks.tolerance', 300);
        $tolerance = is_numeric($tolerance) ? (int) $tolerance : 300;

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw InvalidWebhook::forbidden('The timestamp is outside the tolerance.');
        }
    }

    protected function time(mixed $value): CarbonInterface
    {
        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((float) $value);
            }

            if (is_string($value) && $value !== '') {
                return Carbon::parse($value);
            }
        } catch (Throwable) {
            // Falls through to "now": an event with an unreadable time is still worth storing.
        }

        return Carbon::now();
    }

    /**
     * `<abc@host>` and `abc@host` are the same id; the stored one has no brackets.
     */
    protected function messageId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value, " \t\n\r<>");

        return $value === '' ? null : $value;
    }

    protected function detail(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? PayloadSanitizer::text(trim($value)) : null;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    protected function stored(array $payload): array
    {
        return config('filament-mail-log.webhooks.store_payload', true) ? PayloadSanitizer::clean($payload) : [];
    }

    /**
     * A correlation value as a provider echoes it: a string, or a list of strings (SES tags).
     */
    protected function logId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && preg_match('/^[0-9a-z]{26}$/i', $value) === 1 ? $value : null;
    }
}
