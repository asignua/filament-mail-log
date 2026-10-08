<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Removes live secrets from a text BEFORE it is written to the log.
 *
 * What goes (see `filament-mail-log.redaction`):
 *  - the value of configured query parameters (`signature=…`, `token=…`) in any URL;
 *  - the secret segment after configured path prefixes (`/reset-password/{token}`);
 *  - the value after a credential label (`Password: hunter2`, `token = abc`);
 *  - whatever the configured extra regular expressions match.
 *
 * Fail closed: if a pattern can not be applied (a broken regex, the PCRE backtrack limit on a hostile
 * body), the whole text is replaced by {@see self::FAILED} instead of being stored half-cleaned.
 * The markers are language-neutral on purpose: the redaction runs in a queue worker whose locale is the
 * recipient's, and a translated marker would stay in the database in that language for good.
 */
final class MailRedactor
{
    public const string PLACEHOLDER = '[REDACTED]';

    public const string FAILED = '[redaction failed]';

    public static function apply(?string $text): RedactedText
    {
        if ($text === null || $text === '') {
            return new RedactedText($text, false);
        }

        $redacted = false;

        foreach (self::rules() as [$pattern, $replacement]) {
            $count = 0;
            $result = @preg_replace($pattern, $replacement, $text, -1, $count);

            if (!is_string($result)) {
                return new RedactedText(self::FAILED, true);
            }

            $text = $result;
            $redacted = $redacted || $count > 0;
        }

        return new RedactedText($text, $redacted);
    }

    /**
     * Convenience for short strings (subject, header value, error message): the cleaned text only.
     */
    public static function clean(?string $text): ?string
    {
        return self::apply($text)->text;
    }

    /**
     * @return list<array{0: string, 1: string}> pattern, replacement
     */
    private static function rules(): array
    {
        $rules = [];

        $parameters = self::strings(config('filament-mail-log.redaction.query_parameters'));

        if ($parameters !== []) {
            // The lookbehind accepts `;` so that the `&amp;` separator of HTML bodies works too.
            $rules[] = [
                '~(?<=[?&;#])('.self::alternation($parameters).')=[^&\s"\'<>#]*~i',
                '$1='.self::PLACEHOLDER,
            ];

            // The same parameter inside a percent-encoded URL (`?redirect=https%3A%2F%2F…%3Fsignature%3D…`).
            $rules[] = [
                '~(?<=%3F|%26|%23)('.self::alternation($parameters).')%3D[^&\s"\'<>%]*~i',
                '$1%3D'.self::PLACEHOLDER,
            ];
        }

        $paths = self::strings(config('filament-mail-log.redaction.path_prefixes'));

        if ($paths !== []) {
            $rules[] = [
                '~(/(?:'.self::alternation($paths).'))/[^/\s"\'<>?#]+~i',
                '$1/'.self::PLACEHOLDER,
            ];
        }

        $labels = self::strings(config('filament-mail-log.redaction.credential_labels'));

        if ($labels !== []) {
            // HTML whitespace entities count as spaces. The value runs to the end of the line or the next tag:
            // a generated password may hold any punctuation, and over-redacting is fine for an audit log.
            $space = '(?:\s|&nbsp;|&\#160;|&\#xa0;)';
            $rules[] = [
                '~\b('.self::alternation($labels).')('.$space.'*[:=](?:'.$space.'|<[^>]{0,200}>)*)(?!\[REDACTED\])[^\r\n<]+~i',
                '$1$2'.self::PLACEHOLDER,
            ];
        }

        foreach (self::strings(config('filament-mail-log.redaction.patterns')) as $pattern) {
            $rules[] = [$pattern, self::PLACEHOLDER];
        }

        return $rules;
    }

    /**
     * @param list<string> $words
     */
    private static function alternation(array $words): string
    {
        // Longest first, so `access_token` wins over `token` where both are listed.
        usort($words, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return implode('|', array_map(static fn (string $word): string => preg_quote($word, '~'), $words));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
