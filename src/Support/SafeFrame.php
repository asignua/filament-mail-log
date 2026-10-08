<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Builds the document for the preview iframe of a stored or rendered message.
 *
 * A mail body is untrusted: it may contain text typed by an anonymous visitor (a contact form), so it is
 * never inserted into the panel DOM. The caller puts the result into `srcdoc` (escaped by Blade) of an
 * iframe with an empty `sandbox` attribute: no scripts, no same-origin access, no forms, no top navigation.
 * The CSP meta tag is a second line that also stops remote images, fonts, frames and `<form>` posts.
 */
final class SafeFrame
{
    public static function document(string $html, bool $remoteImages = false): string
    {
        $csp = config($remoteImages ? 'filament-mail-log.preview.csp_remote_images' : 'filament-mail-log.preview.csp');

        if (!is_string($csp) || $csp === '') {
            return $html;
        }

        $meta = '<meta http-equiv="Content-Security-Policy" content="'.e($csp).'">';

        // Never in front of a doctype: content before it switches the iframe to quirks mode.
        if (preg_match('/<head(?:\s[^>]*)?>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            return self::insertAt($html, $meta, $match[0][1] + strlen($match[0][0]));
        }

        if (preg_match('/^\s*<!doctype[^>]*>/i', $html, $match) === 1) {
            return self::insertAt($html, $meta, strlen($match[0]));
        }

        return $meta.$html;
    }

    private static function insertAt(string $html, string $insert, int $offset): string
    {
        return substr($html, 0, $offset).$insert.substr($html, $offset);
    }
}
