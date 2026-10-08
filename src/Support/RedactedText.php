<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Result of {@see MailRedactor::apply()}: the text that is safe to store and whether anything was cut out of it.
 */
final readonly class RedactedText
{
    public function __construct(
        public ?string $text,
        public bool $redacted,
    ) {}
}
