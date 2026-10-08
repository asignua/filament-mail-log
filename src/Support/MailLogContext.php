<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Per-process registry of the messages "in flight" (MessageSending seen, MessageSent not yet) and of the
 * queue job being processed.
 *
 * A worker handles one job at a time, so the static state maps to exactly one attempt; JobProcessing
 * resets it before every job. The state is cleared on every exit path, so a long-lived process (queue
 * worker, Octane) never carries a message from one job or request into the next.
 */
final class MailLogContext
{
    /** @var list<string> */
    private static array $inFlight = [];

    private static ?string $jobId = null;

    public static function push(string $ulid): void
    {
        self::$inFlight[] = $ulid;
    }

    public static function isEmpty(): bool
    {
        return self::$inFlight === [];
    }

    public static function forget(string $ulid): void
    {
        self::$inFlight = array_values(array_filter(
            self::$inFlight,
            static fn (string $known): bool => $known !== $ulid,
        ));
    }

    /**
     * Fallback when the correlation header did not survive the transport: the newest message is the one
     * that has just been sent, because sending is sequential inside a process.
     */
    public static function forgetLast(): ?string
    {
        return array_pop(self::$inFlight);
    }

    /**
     * @return list<string>
     */
    public static function flush(): array
    {
        $ulids = self::$inFlight;
        self::$inFlight = [];

        return $ulids;
    }

    public static function startJob(?string $jobId): void
    {
        self::$inFlight = [];
        self::$jobId = $jobId;
    }

    public static function endJob(): void
    {
        self::$inFlight = [];
        self::$jobId = null;
    }

    public static function jobId(): ?string
    {
        return self::$jobId;
    }

    public static function reset(): void
    {
        self::endJob();
    }
}
