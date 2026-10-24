<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks;

use RuntimeException;

/**
 * A webhook that must be refused. The message is returned to the caller, so it never carries secrets.
 */
final class InvalidWebhook extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /**
     * Missing, wrong or stale signature / credentials.
     */
    public static function forbidden(string $message = 'Invalid signature.'): self
    {
        return new self($message, 403);
    }

    /**
     * Signed, but not something the driver can read.
     */
    public static function malformed(string $message = 'Malformed payload.'): self
    {
        return new self($message, 400);
    }

    /**
     * The driver needs a remote service that failed: the provider should retry.
     */
    public static function unavailable(string $message): self
    {
        return new self($message, 502);
    }
}
