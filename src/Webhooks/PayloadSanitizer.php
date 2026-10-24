<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks;

use Asignua\FilamentMailLog\Support\MailRedactor;

/**
 * Cleans a provider payload before it is stored: keys that hold secrets or signatures are dropped, strings go
 * through the plugin's redactor (signed links in a click event), long strings are cut.
 */
final class PayloadSanitizer
{
    private const string SECRET_KEY = '/signature|token|secret|password|passwd|authorization|api[_-]?key|subscribe_?url|signingcerturl/i';

    private const int MAX_STRING = 2000;

    private const int MAX_DEPTH = 8;

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public static function clean(array $payload, int $depth = 0): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                if ($depth < self::MAX_DEPTH) {
                    $clean[$key] = self::clean($value, $depth + 1);
                }

                continue;
            }

            $clean[$key] = is_string($value) ? self::text($value) : $value;
        }

        return $clean;
    }

    public static function text(string $value): string
    {
        $value = mb_strimwidth(mb_scrub($value, 'UTF-8'), 0, self::MAX_STRING, '…', 'UTF-8');

        return (string) MailRedactor::clean($value);
    }
}
