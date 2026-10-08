<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Per-process registry of the messages "in flight" (MessageSending seen, MessageSent not yet) and of the
 * queue job being processed.
 *
 * A worker handles one job at a time, but the `sync` driver runs a job INSIDE a request or another job, so
 * the state is a stack: JobProcessing pushes the outer frame away, JobProcessed / JobExceptionOccurred
 * bring it back. A real queue worker starts from a clean slate (nothing can be outside it).
 */
final class MailLogContext
{
    /** @var list<string> */
    private static array $inFlight = [];

    private static ?string $jobId = null;

    private static ?string $connection = null;

    private static ?string $jobUuid = null;

    /** @var list<array{inFlight: list<string>, jobId: ?string, connection: ?string, jobUuid: ?string}> */
    private static array $stack = [];

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

    public static function startJob(?string $jobId, ?string $connection = null, bool $nested = false, ?string $jobUuid = null): void
    {
        if ($nested) {
            self::$stack[] = ['inFlight' => self::$inFlight, 'jobId' => self::$jobId, 'connection' => self::$connection, 'jobUuid' => self::$jobUuid];
        } else {
            self::$stack = [];
        }

        self::$inFlight = [];
        self::$jobId = $jobId;
        self::$connection = $connection;
        self::$jobUuid = $jobUuid;
    }

    /**
     * Leaves the current job and restores the frame it was started in (empty for a queue worker).
     */
    public static function endJob(): void
    {
        $frame = array_pop(self::$stack);

        self::$inFlight = $frame['inFlight'] ?? [];
        self::$jobId = $frame['jobId'] ?? null;
        self::$connection = $frame['connection'] ?? null;
        self::$jobUuid = $frame['jobUuid'] ?? null;
    }

    public static function jobId(): ?string
    {
        return self::$jobId;
    }

    /**
     * The payload uuid: unlike the job id it survives a retry on every driver (the database driver re-inserts a released job).
     */
    public static function jobUuid(): ?string
    {
        return self::$jobUuid;
    }

    public static function connection(): ?string
    {
        return self::$connection;
    }

    public static function reset(): void
    {
        self::$inFlight = [];
        self::$jobId = null;
        self::$connection = null;
        self::$jobUuid = null;
        self::$stack = [];
    }
}
