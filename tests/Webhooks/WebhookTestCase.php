<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Webhooks;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogEvent;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class WebhookTestCase extends TestCase
{
    /**
     * The provider under test; the route is POST /mail-log/webhooks/{provider}.
     */
    abstract protected function provider(): string;

    /**
     * @param array<string, mixed> $attributes
     */
    protected function log(array $attributes = []): MailLog
    {
        $log = new MailLog;
        $log->ulid = (string) Str::ulid();
        $log->status = MailStatus::Sent;
        $log->recipient = 'ann@example.test';
        $log->recipients = ['to' => ['ann@example.test'], 'cc' => [], 'bcc' => []];
        $log->subject = 'Hello';
        $log->forceFill($attributes);
        $log->save();

        return $log->fresh() ?? $log;
    }

    /**
     * A raw POST: signatures are computed over the exact bytes, so the body is never re-encoded.
     *
     * @param array<string, string> $headers
     */
    protected function hook(string $body, array $headers = [], ?string $provider = null): TestResponse
    {
        return $this->call(
            'POST',
            '/mail-log/webhooks/'.($provider ?? $this->provider()),
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
            $body,
        );
    }

    protected function enable(string $provider, array $options): void
    {
        config()->set('filament-mail-log.webhooks.providers.'.$provider, array_merge(
            (array) config('filament-mail-log.webhooks.providers.'.$provider),
            ['enabled' => true],
            $options,
        ));
    }

    protected function events(): \Illuminate\Database\Eloquent\Collection
    {
        return MailLogEvent::query()->orderBy('id')->get();
    }
}
