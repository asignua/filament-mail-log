<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks;

use Asignua\FilamentMailLog\Support\MailRedactor;

/**
 * Cleans a provider payload before it is stored: keys that hold secrets or signatures are dropped, so are the
 * recipient's IP address, user agent, client and geolocation (unless `webhooks.keep_client_info`), `{name, value}`
 * header lists lose the headers the log never stores, strings go through the plugin's redactor (signed links in
 * a click event), long strings and long lists are cut.
 */
final class PayloadSanitizer
{
    private const string SECRET_KEY = '/signature|token|secret|password|passwd|authorization|api[_-]?key|subscribe_?url|signingcerturl/i';

    private const string CLIENT_KEY = '/^(ip|ip[_-]?address|remote[_-]?ip|source[_-]?ip|ses:source-ip|user[_-]?agent|useragent|geo|geo[_-]?ip|geolocation|location|client|client[_-]?info|os|platform|device|browser)$/i';

    private const int MAX_STRING = 2000;

    private const int MAX_DEPTH = 8;

    private const int MAX_LIST = 20;

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public static function clean(array $payload, int $depth = 0): array
    {
        $clean = [];
        $keepClient = (bool) config('filament-mail-log.webhooks.keep_client_info', false);
        $ignored = array_values(array_map(static fn (mixed $name): string => strtolower((string) $name), (array) config('filament-mail-log.headers.ignore', [])));

        // A list is cut; a header list (SES: [{name, value}]) first loses what the log never stores.
        if (array_is_list($payload)) {
            $payload = array_values(array_filter($payload, static fn (mixed $item): bool => !self::ignoredHeader($item, $ignored)));
            $payload = array_slice($payload, 0, self::MAX_LIST);
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && (preg_match(self::SECRET_KEY, $key) === 1 || (!$keepClient && preg_match(self::CLIENT_KEY, $key) === 1))) {
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

    /**
     * @param list<string> $ignored
     */
    private static function ignoredHeader(mixed $item, array $ignored): bool
    {
        return is_array($item) && is_string($item['name'] ?? null) && array_key_exists('value', $item)
            && (in_array(strtolower($item['name']), $ignored, true) || preg_match(self::SECRET_KEY, $item['name']) === 1);
    }
}
